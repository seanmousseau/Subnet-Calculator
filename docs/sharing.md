# Shareable URLs & iframe Embedding

## Shareable URLs

The **Share** bar appears below calculation results when `$show_share_bar = true` (the default). It shows a URL that encodes all inputs as GET parameters so anyone who visits it sees the same result immediately.

Example:

```text
https://example.com/subnet-calculator/?tab=ipv4&ip=192.168.1.0&mask=%2F24
```

Set `$canonical_url` in `config.php` to control the base URL used when building shareable links. If left empty the app auto-derives it from the current request.

## iframe Embedding

To embed the calculator in another page:

```html
<iframe
  src="https://example.com/subnet-calculator/"
  width="100%"
  height="600"
  frameborder="0">
</iframe>
```

The calculator detects when it is inside an iframe and:

- Removes minimum-height CSS constraints.
- Posts its content height to the parent via `postMessage` as the page loads and resizes (using `ResizeObserver`), so you can auto-size the iframe.

### Auto-sizing the iframe

```html
<script>
window.addEventListener('message', function (e) {
  if (e.data && e.data.type === 'sc-resize') {
    document.getElementById('calc-frame').style.height = e.data.height + 'px';
  }
});
</script>
<iframe id="calc-frame" src="..." width="100%"></iframe>
```

### Setting a custom background colour at runtime

After the iframe has loaded, send a `sc-set-bg` postMessage to change the calculator's background colour without reloading:

```js
scFrame.addEventListener('load', function () {
  // Set a custom background — must be sent after load
  scFrame.contentWindow.postMessage({ type: 'sc-set-bg', color: '#f0f4f8' }, '*');

  // Reset to the default background (remove override)
  scFrame.contentWindow.postMessage({ type: 'sc-set-bg', color: null }, '*');
});
```

The `color` value must be a hex CSS colour: `#RGB`, `#RGBA`, `#RRGGBB`, or `#RRGGBBAA`. Other formats (named colours, `rgb()`, `hsl()`) are not accepted and will reset the background to the default instead. Pass `null` to remove a previously set override. Messages sent before the iframe `load` event fires are silently ignored because the calculator's listener is not yet running.

### Frame-ancestors policy

Control which origins may embed the calculator via `$frame_ancestors` in `config.php`. The default is `'*'` (any origin may embed). Set to `"'self'"` to restrict to same-origin only, or provide a space-separated list of allowed origins:

```php
$frame_ancestors = "'self' https://intranet.example.com";
```

### Hiding the share bar in embedded deployments

When embedding the calculator as an iframe you may want to hide the Share URL bar. Set `$show_share_bar = false` in `config.php`:

```php
$show_share_bar = false;
```

This hides the bar for all visitors — appropriate when the embed URL is managed externally.
