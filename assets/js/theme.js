/* Inlined into every page's <head> by inc/render.php (so it isn't a render-blocking request).
   The CSP allows it by its sha256 hash: if you change this file, update the hash in the vhost CSP too
   (Admin → Publish & export shows the current value). Order: the visitor's own choice (functional
   cookie "site_theme") > site default (dark, or the device setting). */
/*!INLINE*/(function(){var d=document.documentElement,m=document.cookie.match(/(?:^|; )site_theme=(light|dark)/),t;if(m)t=m[1];else if(d.getAttribute('data-theme-default')==='system'&&window.matchMedia)t=matchMedia('(prefers-color-scheme: light)').matches?'light':'dark';else t='dark';d.setAttribute('data-theme',t)})();
