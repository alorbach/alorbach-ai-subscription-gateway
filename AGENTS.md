# AGENTS.md – Alorbach AI Subscription Gateway (Plugin)

Context for AI agents working on the WordPress plugin.

## Project Overview

WordPress plugin for credit-based AI API billing. Unified Credit (UC) system, BPE tokenization, immutable ledger, OpenAI/Azure/Google support.

- **1 UC** = 0.000001 USD (internal)
- **1000 UC** = 1 Credit (user display)
- **1,000,000 UC** = 1 USD

## Key Paths

| Path | Purpose |
|------|---------|
| `alorbach-ai-subscription-gateway.php` | Main plugin file |
| `includes/` | Ledger, Tokenizer, Cost-Matrix, REST-Proxy, API-Client, User-Display |
| `includes/Payment/` | WooCommerce integration |
| `admin/` | Admin menu, API keys, cost matrix, plans, balance, usage, user credits, Stripe webhook |

## Conventions

- Prefix: `alorbach_` for functions, options, tables
- Namespace: `Alorbach\AIGateway` (Admin: `Alorbach\AIGateway\Admin`, Payment: `Alorbach\AIGateway\Payment`)
- Sanitize inputs: `sanitize_text_field()`, `esc_html()`, `esc_attr()` for output
- Copyright owner: **Andre Lorbach**

## SUMMARIZER

When the user invokes the keyword **SUMMARIZER**, produce a squashed git commit message from the current changes (staged and unstaged). Output it in a copy-ready format.

**Format:**
```
<type>: <short description>

<optional body with bullet points for significant changes>
```

**Types:** `feat`, `fix`, `docs`, `style`, `refactor`, `test`, `chore`

Output the message in a fenced code block so the user can copy it directly.
