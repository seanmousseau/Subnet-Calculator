# Shareable URLs & iframe Embedding

## Shareable URLs

The **Share** bar appears below calculation results when `$show_share_bar = true` (the default) and `$canonical_url` is set in `config.php`. It shows a URL that encodes all inputs as GET parameters so anyone who visits it sees the same result immediately.

Example:

```text
https://example.com/subnet-calculator/?tab=ipv4&ip=192.168.1.0&mask=%2F24
```

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
  if (e.data && e.data.type === 'sc-height') {
    document.getElementById('calc-frame').style.height = e.data.height + 'px';
  }
});
</script>
<iframe id="calc-frame" src="..." width="100%"></iframe>
```

### Frame-ancestors policy

Control which origins may embed the calculator via `$frame_ancestors` in `config.php`. Default is `'self'` (same origin only). Set to `'*'` to allow any origin, or to a specific domain.
