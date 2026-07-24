# WhatsApp Multi-Session Single Account Test

## Preconditions

- Back up the current VPS `.env` before any change.
- Confirm the current PM2 process is healthy and the legacy runtime is stable.
- Confirm the current WhatsApp session is already connected on VPS.
- Confirm the target Laravel `whatsapp_accounts.id` for the single-account test.
- Confirm the target `session_name` in Laravel matches the existing LocalAuth session storage expectation.
- Confirm `WHATSAPP_ENGINE_INTERNAL_TOKEN` is already configured on VPS.
- Confirm Laravel API is reachable from the engine host.
- Do not delete or reset `.wwebjs_auth` or `.wwebjs_cache`.

## VPS Environment Changes For The Test

Use placeholders only. Do not copy real values into this document.

```env
WHATSAPP_MULTI_SESSION_ENABLED=true
WHATSAPP_MULTI_SESSION_ACCOUNT_IDS=<ACCOUNT_ID>
WHATSAPP_ENGINE_INTERNAL_TOKEN=<EXISTING_INTERNAL_TOKEN>
```

Notes:
- Keep `ENGINE_API_TOKEN` and `WHATSAPP_SESSION_ID` in place during the first test for fast rollback.
- Do not delete the legacy variables during the first rollout.
- Keep `WHATSAPP_MULTI_SESSION_ACCOUNT_IDS` limited to one account id for the first VPS test.

## Verification After Startup

- Check PM2 process state.
- Check engine logs for `Selected runtime: multi-session`.
- Check logs for the fetched session count and the filtered allowlist count.
- Confirm only the target `accountId` is started.
- Confirm the managed session reaches `ready` without creating an unexpected QR.
- Confirm the message worker starts for that account only.
- Trigger one controlled application message.
- Confirm the Laravel lifecycle becomes `pending -> queued -> sent`.
- Confirm no second account is loaded.
- Confirm no duplicate send is logged.

## Success Criteria

- Only the selected account id is started.
- The existing WhatsApp session is restored.
- No unexpected QR is generated.
- One test message is delivered successfully through the multi-session path.
- Laravel records `sent` correctly.
- No duplicate send occurs.
- No restart loop appears.
- Memory usage remains initially stable.

## Rollback

- Set `WHATSAPP_MULTI_SESSION_ENABLED=false`.
- Keep `ENGINE_API_TOKEN` unchanged.
- Keep `WHATSAPP_SESSION_ID` unchanged.
- Restart PM2.
- Confirm logs show `Selected runtime: legacy`.
- Confirm the legacy runtime reconnects using the existing LocalAuth data.
- Do not delete `.wwebjs_auth`.
- Do not delete any cache directory.

## Immediate Stop Conditions

- An unexpected QR appears instead of restoring the current session.
- More than one `accountId` starts.
- Duplicate messages are observed.
- Messages from another account appear.
- A restart loop begins.
- Repeated auth errors appear.
- Memory usage rises abnormally.
- Rollback to legacy cannot be completed safely.