(function () {
    const el = document.getElementById("social-auth-result");
    if (!el) return;

    const payload = {
        type: el.dataset.type || "worksphere-social-auth",
        status: el.dataset.status || "error",
        redirect: el.dataset.redirectUrl || "",
        message: el.dataset.message || "",
    };

    const targetOrigin = window.location.origin;

    try {
        if (window.opener && !window.opener.closed) {
            window.opener.postMessage(payload, targetOrigin);
            setTimeout(function () {
                window.close();
            }, 150);
            return;
        }
    } catch (error) {
        console.error("Failed to notify social auth opener:", error);
    }

    if (payload.redirect) {
        window.location.replace(payload.redirect);
    }
})();
