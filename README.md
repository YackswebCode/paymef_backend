## PayMef API

JSON API for the PayMef school payments platform. All routes live under `/api` and use Sanctum bearer tokens unless noted.

### Roles

`admin` (platform), `school_admin` (one school), `student`, `parent` (guardian). Every query is scoped: school staff see their school, students see their own invoices, guardians see their linked children.

### Endpoints

| Method | Path | Who | Notes |
| --- | --- | --- | --- |
| GET | `/api/health` | public | liveness |
| POST | `/api/auth/register` | public | students and parents only |
| POST | `/api/auth/login` | public | returns bearer token |
| POST | `/api/auth/forgot-password` | public | generic response, no account enumeration |
| POST | `/api/auth/reset-password` | public | also accepts invite tokens |
| GET | `/api/auth/email/verify/{id}/{hash}` | signed URL | admin accounts are exempt |
| GET | `/api/auth/me` | auth | current user + school |
| PATCH | `/api/auth/profile` | auth | name, phone |
| POST | `/api/auth/password` | auth | requires `current_password`, signs out other devices |
| GET/POST | `/api/invoices` | auth / school staff | list (filter `status`, `student_id`, `school_id`), create |
| POST | `/api/invoices/bulk` | school staff | up to 200 invoices, all-or-nothing |
| GET | `/api/invoices/{invoice}` | scoped | includes `outstanding_minor` |
| PATCH | `/api/invoices/{invoice}` | school staff | description, due date, amount (amount locked once paid) |
| POST | `/api/invoices/{invoice}/issue` | school staff | draft → issued |
| POST | `/api/invoices/{invoice}/cancel` | school staff | only while nothing is paid |
| POST | `/api/invoices/{invoice}/pay` | scoped | optional `amount_minor`, optional `Idempotency-Key` |
| GET | `/api/payments` | scoped | history, filter `status`, `invoice_id` |
| GET | `/api/payments/{reference}` | scoped | re-verifies with Paystack |
| POST | `/api/payments/{reference}/refund` | school staff | full or partial `amount_minor` |
| POST | `/api/webhooks/paystack` | public | HMAC-SHA512 over the raw body |
| GET | `/api/wallet` | auth | own balance |
| GET | `/api/schools/{school}/wallet` | scoped | school balance |
| GET | `/api/schools/{school}/summary` | scoped | invoiced / collected / outstanding |
| GET | `/api/ledger` | scoped | paginated entries, filter `entry_type` |
| GET | `/api/children` | parent | linked students + outstanding |
| GET/POST/DELETE | `/api/students/{student}/guardians` | school staff | manage guardian links |
| GET/POST | `/api/schools` | admin (create) | list is scoped |
| PATCH | `/api/schools/{school}` | admin | name, slug, verification status |
| POST | `/api/schools/{school}/admins` | admin | provisions a school admin and emails an invite |
| GET/POST | `/api/students` | school staff | list (filter `q`, `school_id`), provision one |
| POST | `/api/students/bulk` | school staff | up to 500, duplicates reported as `skipped` |

### Money model

Amounts are integers in the minor unit (`amount_minor`, kobo). Invoices are never mutated by a payment: successful payment attempts post immutable `ledger_entries` rows with a unique `idempotency_key`, so a replayed webhook cannot credit twice. Refunds post a compensating `refund` entry. Balances are always derived from the ledger, never stored.

### Background work

Queued mail (receipts, invites, reminders) needs a worker, and the scheduled jobs need an entry in cron:

```sh
php artisan queue:work --tries=3
# crontab
* * * * * cd /path/to/paymef-backend && php artisan schedule:run >> /dev/null 2>&1
```

| Command | Schedule | Purpose |
| --- | --- | --- |
| `payments:reconcile` | every 15 min | re-verify pending attempts so a lost webhook cannot strand money |
| `invoices:mark-overdue` | hourly | issued/partially paid past due → `overdue` |
| `invoices:send-reminders` | daily 08:00 | students and guardians, 3-day cooldown per invoice |
| `sanctum:prune-expired` | daily | expire stale tokens |

Paystack must be able to reach `POST /api/webhooks/paystack`; locally that means a tunnel (the panel callback still settles via `GET /api/payments/{reference}` because it verifies server-side).

### Tests

```sh
php artisan test
```
