# Asyntai AI Chatbot for Contao

Adds the [Asyntai](https://asyntai.com) AI chat assistant to a Contao website.
The chat bubble appears on every front end page and answers visitor questions
around the clock, using your own pages, documents and product information.

## Requirements

- Contao 5.3 or later
- PHP 8.2 or later
- A free Asyntai account

## Installation

With the Contao Manager, search for **Asyntai** and install the package.

With Composer:

```bash
composer require asyntai/contao-chatbot-bundle
```

## Setup

1. In the Contao back end, go to **System → Asyntai AI Chatbot**.
2. Click **Get started** and sign in, or create a free Asyntai account.

The chat bubble now appears on the front end. You can change the assistant, its
colours, its greeting and its knowledge at any time on asyntai.com. You do not
need to change anything in Contao for that.

You can also connect by hand: copy the snippet from **Setup & Integration** in
the Asyntai dashboard, and paste it into **Asyntai widget ID** under
**System → Settings**.

## Reads your pages directly

With **Share my published pages with the chatbot** switched on, Asyntai reads
your pages straight from your website, so the chatbot's answers stay up to date.
It reads the same pages as your XML sitemap, including news, events and FAQ
entries. Drafts, members-only pages and pages marked "noindex" are never sent.

Every read is signed with a key that only your website and Asyntai know. You can
switch sharing off at any time on the same page.

## How it works

The extension adds one small script to the end of the body, through the
`generatePage` hook. The script loads the widget only after the page load event,
so it never delays your own content.

No visitor data is sent to Asyntai until a visitor opens the chat.

## Licence

MIT
