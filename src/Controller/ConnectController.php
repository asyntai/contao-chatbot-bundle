<?php

declare(strict_types=1);

namespace Asyntai\ContaoChatbotBundle\Controller;

use Asyntai\ContaoChatbotBundle\State;
use Contao\BackendUser;
use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The connect handshake behind the "Asyntai AI Chatbot" back end page.
 *
 * Every call needs a signed-in back end administrator and the Contao request
 * token. Nothing is ever loaded from Asyntai as a script inside the back end.
 */
#[Route('/contao/_asyntai', defaults: ['_scope' => 'backend', '_token_check' => false])]
final class ConnectController
{
    /** How long a handshake stays valid, in seconds. */
    private const STATE_TTL = 900;

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Security $security,
        #[Autowire(service: 'contao.csrf.token_manager')]
        private readonly CsrfTokenManagerInterface $tokenManager,
        #[Autowire(param: 'contao.csrf_token_name')]
        private readonly string $tokenName,
    ) {
    }

    #[Route('/{action}', name: 'asyntai_chatbot_connect', methods: ['POST'], requirements: ['action' => 'prepare|poll|finish|share|status|disconnect'])]
    public function __invoke(Request $request, string $action): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof BackendUser || !$this->security->isGranted('ROLE_ADMIN')) {
            return $this->fail('Only administrators can change this setting.', 403);
        }

        $token = (string) $request->request->get('REQUEST_TOKEN', '');

        if (!$this->tokenManager->isTokenValid(new CsrfToken($this->tokenName, $token))) {
            return $this->fail('Your session has expired. Please reload the page.', 403);
        }

        $this->framework->initialize();

        $result = match ($action) {
            'prepare' => $this->prepare($request, $user),
            'poll' => $this->poll($request),
            'finish' => $this->finish($request),
            'share' => $this->share($request),
            'status' => $this->status(),
            'disconnect' => $this->disconnect(),
        };

        State::save();

        return $result;
    }

    /**
     * Start a connect. With sharing ticked, a fresh feed token is parked at
     * Asyntai first. Without it, nothing is parked.
     */
    private function prepare(Request $request, BackendUser $user): JsonResponse
    {
        $share = '1' === $request->request->get('share');
        $state = 'contao_'.bin2hex(random_bytes(12));
        $siteUrl = State::detectSiteUrl($request->getSchemeAndHttpHost(), $request->getBasePath());

        if ($share) {
            $token = bin2hex(random_bytes(24));
            $body = State::httpPostJson(State::origin().'/api/v1/wp-search/stage/', [
                'state' => $state,
                'platform' => 'contao',
                'feed_protocol' => 'store_feed_v1',
                // A chatbot install never unlocks the paid AI Search bar.
                'product' => 'chat',
                'site_url' => $siteUrl,
                'feed_url' => $siteUrl.State::FEED_PATH,
                'feed_token' => $token,
            ]);

            if (null === $body) {
                if ('' !== State::$lastError) {
                    return $this->fail('Asyntai refused the request: '.State::$lastError);
                }

                return $this->fail('Could not reach Asyntai. Check that this website can make outgoing HTTPS requests.');
            }

            // Kept aside until the sign-in finishes. The feed accepts it in the
            // meantime, because Asyntai starts reading at sign-in.
            State::set(State::KEY_PENDING_TOKEN, $token);
        } else {
            State::forget(State::KEY_PENDING_TOKEN);
        }

        State::set(State::KEY_SITE_URL, $siteUrl);
        State::set(State::KEY_STATE, $state);
        State::set(State::KEY_STATE_AT, (string) time());

        $url = State::origin().'/wp-auth?'.http_build_query([
            'state' => $state,
            'platform' => 'contao',
            'product' => 'chat',
            'site_url' => $siteUrl,
            'wp_email' => (string) $user->email,
            'lang' => substr((string) $request->getLocale(), 0, 2),
        ]);

        return new JsonResponse(['state' => $state, 'url' => $url]);
    }

    /** Has the owner finished signing in? */
    private function poll(Request $request): JsonResponse
    {
        $state = trim((string) $request->request->get('state', ''));

        // Only the handshake this website just started is ever polled.
        if ('' === $state || $state !== State::get(State::KEY_STATE)) {
            return $this->fail('Unknown handshake.');
        }

        if (time() - (int) State::get(State::KEY_STATE_AT) > self::STATE_TTL) {
            return $this->fail('This connection attempt has expired. Please press Get started again.');
        }

        $body = State::httpGet(State::origin().'/api/v1/wp-search/connect-status/?state='.rawurlencode($state));
        $decoded = null === $body ? null : json_decode($body, true);

        if (!\is_array($decoded) || empty($decoded['ready']) || empty($decoded['data']['site_id'])) {
            return new JsonResponse(['ready' => false]);
        }

        return new JsonResponse([
            'ready' => true,
            'site_id' => (string) $decoded['data']['site_id'],
            'script_url' => (string) ($decoded['data']['script_url'] ?? ''),
            'account_email' => (string) ($decoded['data']['account_email'] ?? ''),
        ]);
    }

    /** Store the sign-in result. A parked feed token becomes the live one. */
    private function finish(Request $request): JsonResponse
    {
        $siteId = trim((string) $request->request->get('site_id', ''));

        if (!preg_match('/^[A-Za-z0-9_-]{6,64}$/', $siteId)) {
            return $this->fail('Invalid widget ID.');
        }

        if ('' === State::get(State::KEY_STATE)) {
            return $this->fail('Unknown handshake.');
        }

        State::set(State::KEY_WIDGET_ID, $siteId);

        $scriptUrl = trim((string) $request->request->get('script_url', ''));

        if ('' !== $scriptUrl && preg_match('#^https://#i', $scriptUrl)) {
            State::set(State::KEY_SCRIPT_URL, $scriptUrl);
        }

        $email = trim((string) $request->request->get('account_email', ''));

        if ('' !== $email) {
            State::set(State::KEY_ACCOUNT_EMAIL, mb_substr($email, 0, 255));
        }

        $pending = State::get(State::KEY_PENDING_TOKEN);

        if ('' !== $pending) {
            State::set(State::KEY_FEED_TOKEN, $pending);
            State::set(State::KEY_FEED_ENABLED, '1');
            State::forget(State::KEY_PENDING_TOKEN);
        }

        State::forget(State::KEY_STATE);
        State::forget(State::KEY_STATE_AT);

        return new JsonResponse(['ok' => true]);
    }

    /** Switch page sharing on or off. */
    private function share(Request $request): JsonResponse
    {
        $on = '1' === $request->request->get('enabled');

        if ($on && '' === State::get(State::KEY_FEED_TOKEN)) {
            // Connected before sharing existed, or after a reset: Asyntai has no
            // token for this website yet, so the owner signs in once more.
            return new JsonResponse(['ok' => false, 'needs_connect' => true]);
        }

        State::set(State::KEY_FEED_ENABLED, $on ? '1' : '0');

        return new JsonResponse(['ok' => true, 'enabled' => $on]);
    }

    /** What Asyntai says about the last read of the shared pages. */
    private function status(): JsonResponse
    {
        $siteId = State::widgetId();

        if ('' === $siteId) {
            return new JsonResponse(['sync' => null]);
        }

        $body = State::httpGet(State::origin().'/api/v1/search-widget/status/?widget_id='.rawurlencode($siteId), 8);
        $decoded = null === $body ? null : json_decode($body, true);
        $sync = \is_array($decoded) && isset($decoded['sync']) && \is_array($decoded['sync']) ? $decoded['sync'] : null;

        return new JsonResponse(['sync' => null === $sync ? null : [
            'status' => (string) ($sync['status'] ?? ''),
            'count' => (int) ($sync['count'] ?? 0),
            'error' => (string) ($sync['error'] ?? ''),
        ]]);
    }

    /** Forget the connection. The feed token goes with it. */
    private function disconnect(): JsonResponse
    {
        foreach ([
            State::KEY_WIDGET_ID, State::KEY_SCRIPT_URL, State::KEY_ACCOUNT_EMAIL,
            State::KEY_FEED_TOKEN, State::KEY_FEED_ENABLED, State::KEY_PENDING_TOKEN,
            State::KEY_STATE, State::KEY_STATE_AT,
        ] as $key) {
            State::forget($key);
        }

        return new JsonResponse(['ok' => true]);
    }

    private function fail(string $message, int $status = 400): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'error' => $message], $status);
    }
}
