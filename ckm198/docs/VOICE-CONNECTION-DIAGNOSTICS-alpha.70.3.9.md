# VOICE CONNECTION DIAGNOSTICS — alpha.70.3.9

Adds live diagnostics for Cartesia/Sergey voice connection in participant, host, and scoreboard pages.

Checks: WordPress AJAX, short-lived voice token, WebSocket open, Gateway join, Cartesia health/TTS readiness.

The UI records HTTP errors, request timeouts, WebSocket close codes/reasons, Gateway protocol errors, and Cartesia voice events. No secrets are exposed to the browser.
