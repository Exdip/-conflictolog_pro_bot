# Tenant voice origin fix — 0.3.23.41

Voice AJAX origin validation now compares `HTTP_ORIGIN` with the actual request `HTTP_HOST` instead of the canonical WordPress `home_url()` host. This allows registered tenant rooms such as `222.ckkm.ru` to request short-lived voice tokens without a false HTTP 403 while still rejecting cross-origin requests and unknown Host headers.
