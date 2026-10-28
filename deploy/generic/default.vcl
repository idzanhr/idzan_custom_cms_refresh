vcl 4.1;
# Varnish for domain.com — caches the static public site. The admin never passes through here
# (nginx sends it straight to PHP), and the CMS clears this cache after every change.
#
# Flow:  browser → nginx :443 (TLS) → Varnish :6081 → nginx :8080 (files + PageSpeed)


backend default {
    .host = "127.0.0.1";
    .port = "8080";
    .connect_timeout = 2s;
    .first_byte_timeout = 30s;
}

# Only the server itself may clear the cache.
acl purgers {
    "127.0.0.1";
    "::1";
}

sub vcl_recv {
    # Cache clearing from the CMS (BAN) or PageSpeed (PURGE).
    if (req.method == "BAN") {
        if (!client.ip ~ purgers) { return (synth(405, "Not allowed")); }
        ban("req.http.host == " + req.http.host + " && req.url ~ " + req.http.X-Ban-Url);
        return (synth(200, "Banned"));
    }
    if (req.method == "PURGE") {
        if (!client.ip ~ purgers) { return (synth(405, "Not allowed")); }
        return (purge);
    }
    if (req.method != "GET" && req.method != "HEAD") { return (pass); }

    # The public site is static and the same for everyone: cookies never matter here
    # (the consent banner works in the browser), so drop them to keep everything cacheable.
    unset req.http.Cookie;
    unset req.http.Authorization;

    # Ignore tracking parameters so they don't split the cache.
    set req.url = regsuball(req.url, "(\?|&)(utm_[a-z]+|fbclid|gclid|mc_[a-z]+)=[^&]*", "\1");
    set req.url = regsub(req.url, "(\?|&)+$", "");
    return (hash);
}

sub vcl_backend_response {
    unset beresp.http.Set-Cookie;
    if (bereq.url ~ "\.(css|js|jpe?g|png|webp|avif|gif|svg|ico|woff2?)(\?.*)?$" || bereq.url ~ "\.pagespeed\.") {
        set beresp.ttl = 7d;
    } elsif (beresp.status == 200 || beresp.status == 301) {
        set beresp.ttl = 24h;          # pages: the CMS bans them on every change anyway
    } elsif (beresp.status == 404) {
        set beresp.ttl = 2m;
    }
    set beresp.grace = 6h;             # keep serving if the backend restarts
}

sub vcl_deliver {
    unset resp.http.Via;
    unset resp.http.X-Varnish;
    if (obj.hits > 0) { set resp.http.X-Cache = "HIT"; } else { set resp.http.X-Cache = "MISS"; }
}
