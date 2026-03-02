# Content Core v1.6.2 — Deployment Checklist

## 🔧 What Was Fixed

- **Version unified** → 1.6.2 (was 1.6.0/1.6.1)
- **Asset error handling** → No longer breaks on read-only filesystems
- **JSON safety** → 6 locations now check for corrupted JSON
- **File read guards** → Diagnostics page works on restricted hosts

---

## ✅ Pre-Deployment

- [ ] PHP ≥ 8.0 on target server
- [ ] wp-content directory writable
- [ ] REST API accessible (/wp-json/)
- [ ] No conflicts in wp-content/debug.log

---

## 🚀 Deployment Steps

1. Delete old Content-Core plugin folder
2. Extract new Content-Core v1.6.2 to wp-content/plugins/
3. Activate in WordPress Admin
4. Run site diagnostics (Admin → Content Core → Diagnostics)
5. Check admin dashboard loads without CSS/JS errors

---

## ✔️ Post-Deployment Verification

- [ ] Admin dashboard loads (no broken layouts)
- [ ] No 500 errors in debug.log
- [ ] REST API routes accessible: curl http://site.com/wp-json/
- [ ] Diagnostics health check passes
- [ ] File permissions warning doesn't appear on restricted hosts

---

## 🐛 If Something Breaks

**CSS/JS not loading?**
- Check Assets.php error handling applied (try-catch exists)
- Verify wp-content/plugins/Content-Core/src/cache/ exists and is writable

**REST API 404 errors?**
- Confirm permalinks not set to "Plain" (must be Pretty)
- Flush rewrite rules: `wp rewrite flush`

**JSON errors in REST response?**
- Check logs for "JSON decode failed" warning
- Verify database content not corrupted

**Diagnostics warnings on Hostinger?**
- Normal on restricted hosts — not a problem
- File guards prevent errors from showing

---

## 📋 Version Check

```bash
# Should all show 1.6.2
grep "Version:" content-core.php
grep "CONTENT_CORE_VERSION" content-core.php
grep "return '" src/Plugin.php | grep version
```

---

## 🔍 Code Changes Summary

| File | Changes | Why |
|------|---------|-----|
| content-core.php | Version → 1.6.2 | Unified version |
| src/Plugin.php | get_version() → 1.6.2 | Unified version |
| src/Admin/Assets.php | try-catch added (47 lines) | Handle readonly filesystems |
| src/Modules/RestApi/RestApiModule.php | json_last_error checks (36 lines, 6 locations) | Prevent JSON corruption |
| src/Modules/Diagnostics/Checks/ThemeInjectionCheck.php | @file_get_contents guards (20 lines) | Safe file reads |

---

## 📞 Quick Troubleshooting

| Issue | Check |
|-------|-------|
| Assets missing | wp-content/plugins/Content-Core/src/cache/ readable? |
| REST 404 | Permalink structure not "Plain"? |
| Database errors | Corrupted JSON in field values? |
| Hostinger issues | Normal — expected on shared hosting |

---

**Status:** Production Ready ✅  
**Version:** 1.6.2  
**Date:** March 2, 2026
