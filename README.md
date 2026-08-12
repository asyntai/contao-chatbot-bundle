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

1. Create a free account at [asyntai.com](https://asyntai.com) and add your website.
2. Open **Setup & Integration** in the Asyntai dashboard and copy the snippet.
3. In the Contao back end, go to **System → Settings**.
4. Paste the snippet into **Asyntai widget ID** and save.

The chat bubble now appears on the front end. To switch the chat off, clear the
field.

You can change the assistant, its colours, its greeting and its knowledge at any
time on asyntai.com. You do not need to change anything in Contao for that.

## How it works

The extension adds one small script to the end of the body, through the
`generatePage` hook. The script loads the widget only after the page load event,
so it never delays your own content.

No visitor data is sent to Asyntai until a visitor opens the chat.

## Licence

MIT
