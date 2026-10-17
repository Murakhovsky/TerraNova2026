# COS ChatGPT Plan Integration (Partner pilot)

Status: feature-gated, not enabled. Real end-to-end acceptance is pending OpenAI approval.

**Eligibility:** COS is a remotely hosted commercial website, not a locally hosted open-source OAuth client. The OSS dynamic-registration flow does not grant COS permission to use ChatGPT plan credits. Apply for approval at https://openai.com/form/sign-in-with-chatgpt-interest/ and follow https://developers.openai.com/siwc/website.

## Deployment settings

* COS_SIWC_ENABLED=0 (set to 1 only after approval).
* COS_SIWC_CLIENT_ID: approved OpenAI OAuth client ID.
* COS_SIWC_CLIENT_SECRET: confidential client secret, if OpenAI registers the client for client_secret_post; otherwise blank.
* COS_SIWC_REDIRECT_URI=https://company-os.shop/admin/settings/llm/chatgpt/callback (must match OpenAI registration exactly).

## Current supported flow

1. Sign in to COS using COS authentication and open /admin/settings/llm.
2. Authenticated tenant admin starts account linking with a CSRF-protected POST.
3. Server stores state, PKCE verifier, nonce, actor, tenant and ten-minute expiry in the COS session.
4. OAuth callback consumes transaction once, validates state and tenant/user, exchanges code, verifies ID-token RSA signature and issuer/audience/expiry/nonce, and checks chatgpt.tokens.use.direct scope.
5. Access and refresh tokens are stored as existing encrypted Platform Settings secrets, separated by organization and user ID.
6. Explicit admin smoke call uses Responses API with stream=true, store=false, JSON schema and response.completed SSE event; access token refresh stays scoped to this user.
7. Disconnect deletes the local secret. User may revoke provider access in ChatGPT settings.

This is personal model testing, **not** a login replacement or automatic provider selection. It does not expose ChatGPT conversation history or OpenAI API keys. Agent/CRON/Workflow remain on their existing provider; personal plan scopes must not be used as a tenant-wide credential. The provider is registered in the central LLM registry but refuses non-interactive use cases.

## Acceptance limitations

The integration cannot be claimed live until a partner-approved OAuth client, real ChatGPT consent, refresh rotation, plan-eligible model, multi-instance session callback and production token secrecy are verified. After initial smoke tests, future production work must add atomic credential-refresh locking and separate per-user authorization for interactive LLM actions. Plan usage is subject to subscription limits, not an unlimited free API.
