# Customer-facing branding audit

Scope: staging-only review of the customer invoice payment page, invoice email, payment/receipt outputs, invoice PDF, contract/eSignature/customer agreement output, and customer portal/billing pages. This audit intentionally recommends visual standardization only; it does not change payment, Stripe, eSignature, onboarding, invoice, webhook, or database behavior.

## Baseline observations

- The polished payment landing page should be the visual source of truth. It already uses a dark hero, horizontal light logo, trust pill, rounded summary cards, green/blue status pills, prominent checkout card, and friendly customer-facing copy.
- Shared shell styles exist in `css/portal_shell.css`, including color variables, glass cards, buttons, tables, metrics, and status helpers, but multiple customer-facing pages still redefine matching patterns inline.
- Logo assets exist for horizontal, stacked, icon, dark, light, favicon, PNG, JPG, and SVG variants. PDF generation currently prefers JPG/PNG before SVG, which is helpful for Dompdf compatibility, but the audit should confirm transparent PNG/JPG exports match the intended dark and light backgrounds.
- Invoice and contract PDFs are visually related only at a basic level. Both use the same company name, but their color systems, logo candidate order, headers, table treatment, and status presentation diverge.
- Receipt/payment confirmation appears to rely primarily on Stripe-hosted receipt URLs and local payment-status views. There is no OPS-native customer receipt email/PDF renderer surfaced by the reviewed code paths, so any branded receipt work should start by deciding whether OPS should own a receipt template or continue to defer customer receipt UX to Stripe.

## Findings by surface

### 1. Customer invoice payment landing page

- Keep this page as the design anchor: `public-pay-hero`, `public-pay-brand`, `public-pay-summary`, `public-pay-panel`, `public-pay-action-card`, and `public-pay-status-pill` define the most complete customer-ready system.
- The page still keeps its style block local instead of contributing reusable public-brand classes. The card, badge, button, panel, and responsive-grid rules should be extracted into a shared customer-brand stylesheet after the audit.
- The landing page uses `mmit-logo-horizontal-light.svg`, which works on the dark hero. If the same component is reused on light PDF/email surfaces, create a documented logo-use matrix rather than swapping ad hoc.

### 2. Invoice email template

- The invoice email is branded and customer-facing, but it does not fully match the payment page. It uses a white card on a pale lavender background, a 56px square logo/icon area, `#10233f`, `#1d4ed8`, `#eef2ff`, and inline table/card styles.
- The email copy includes internal product language: “secure OPS payment page” and “powered by the OPS payment portal.” Replace with customer-facing language such as “secure payment page” or “Midwest Managed IT payment portal.”
- Email buttons and summary cards should adopt the same primary button color, radius, amount hierarchy, and status language as the payment page while remaining email-client-safe with inline CSS.
- The email logo helper should be standardized with the same brand asset decision used by web and PDFs; confirm whether email clients should receive a hosted PNG rather than SVG.

### 3. Receipt/payment confirmation email and receipt PDF

- No OPS-native receipt confirmation email/PDF template was found in the reviewed payment code paths. Stripe receipt URLs are stored and linked from internal payment views, while the payment page tells customers that receipt and payment status update after Stripe confirms payment.
- Recommended decision: either keep Stripe as the official receipt surface and style surrounding OPS pages accordingly, or add an OPS-branded receipt confirmation email/PDF in a later functional change. That later work should be separately scoped because it affects payment communications.
- If OPS adds receipts later, build them from the same shared invoice/payment brand tokens: logo, amount summary, payment date/method/status, invoice number, customer name, and support footer.

### 4. Invoice PDF generation

- The invoice PDF embeds a local logo and uses a clean header, legal name, billing email, status pill, bill-to card, metadata card, dark table header, summary table, notes/payment cards, and paid watermark.
- Styling is hardcoded inside the renderer. Its colors and spacing do not match the payment page or invoice email exactly, and status colors include a potential mismatch where the issued pill uses a blue background with teal text.
- The invoice line-item secondary text currently includes internal service/revenue account metadata when available. For a customer-facing PDF, revenue account names/codes can feel internal/admin-oriented and should be hidden or mapped to customer-friendly service descriptions.
- The default payment text says payment links will appear after merchant setup is complete. That wording is implementation/setup-facing and should be replaced before production customer use.

### 5. Contract/eSignature/customer agreement output

- The contract PDF renderer uses a separate layout and logo-candidate list from the invoice PDF. It prefers `mmit-logo-horizontal-light.jpg/png` and falls back to a company-name text block.
- Contract PDF styles are more document/legal oriented: DejaVu Sans, gray/blue summary table, service cards, terms sections, notes box, and signature lines. This is appropriate for agreements, but should still share brand fundamentals with invoices: logo placement, header hierarchy, table header colors, card borders, footer treatment, and primary accent color.
- eSignatures sends are template-based and only pass placeholder values such as signer name, company name, service plan, monthly amount, and metadata. Visual branding of the signing ceremony/customer agreement likely lives in the provider template, so update the provider template alongside OPS PDF changes.
- The eSignature status/operations page includes customer-related language, but it is mostly internal OPS. Do not prioritize its styling over the signed customer agreement template.

### 6. Client portal login page

- The client login page uses the shared portal shell CSS but adds local layout and eyebrow styles. Copy is distinctive but too casual in places: “spare key,” “rainy-day moment,” and “fast lane” may be acceptable for brand voice, but should be normalized if the desired tone is polished/professional.
- The page has no visible logo in the reviewed login markup, unlike the payment and billing pages. Add a consistent topbar/brand mark before other visual tuning.
- Portal access pages repeat the same local `eyebrow`, card, action, and grid patterns that should become shared customer-brand classes.

### 7. Client portal dashboard/billing pages

- The billing center has a customer-facing topbar with logo and theme toggle, but it also includes audit/test wording: “Beta Notes,” “For tonight’s outside-eye test,” and “Make sure the email Doc uses…” These should be removed or hidden from customer-facing staging demos.
- Billing copy includes casual/internal metaphors such as “swivel chair routine,” “lanes,” and “support clutter.” Standardize tone across payment, billing, and portal pages.
- Billing tables, summary tiles, cards, and detail panels are close to the shared shell system, but the page still defines local CSS for cards, tables, chips, grids, and eyewbrows. Move reusable customer-facing components into the shared brand stylesheet.
- Portal access dashboard wording includes “vendor portals,” “one beige hallway,” “good hallway,” and “Syncro” references. Decide whether customers should see vendor names and metaphors, or whether this should be reframed as “workspace,” “billing,” and “support.”

## Recommended implementation order

1. **Define brand tokens and asset rules first.** Document approved colors, radii, shadows, logo variants by background, favicon usage, PDF-safe raster assets, and email-safe hosted image rules. Confirm whether transparent PNG exports are needed for PDFs/email and add them before template work.
2. **Extract shared customer UI classes without changing behavior.** Promote payment-page patterns into a shared customer-brand stylesheet: hero, topbar, logo lockup, trust/status pill, card/panel, action card, summary grid, buttons, tables, empty states, and support footer. Keep existing payment/eSignature/webhook logic untouched.
3. **Clean customer-facing wording.** Remove “OPS,” “beta,” “outside-eye test,” setup/merchant wording, and vendor/internal metaphors from customer pages and templates. Standardize nouns: “payment page,” “billing center,” “client portal,” “workspace,” “invoice,” “receipt,” and “agreement.”
4. **Align web pages next.** Apply shared classes to the payment landing page only as a no-op visual extraction, then billing login/dashboard, then portal access/login pages. Verify responsive behavior and light/dark logo swaps.
5. **Align invoice email.** Keep inline email CSS, but base values on the same tokens. Use customer-safe copy, consistent logo dimensions, matching primary CTA, invoice summary card, and support footer.
6. **Align PDFs.** Create shared PDF brand constants/helpers for logo selection, colors, headers, status pills, table headers, notes/payment cards, and footers. Update invoice PDF first, then contract PDF/agreement output to keep legal layout intact while sharing brand fundamentals.
7. **Decide receipt ownership.** If Stripe remains the receipt source, ensure payment and billing pages link to it with consistent customer language. If OPS needs branded receipts, scope a separate receipt email/PDF feature after the audit because it changes payment communications.
8. **Update eSignature provider template.** Mirror the contract PDF brand changes in the eSignatures template and placeholders. Validate that signed output, audit trail, and archived PDFs remain unaffected by branding-only updates.
9. **Regression pass.** Run visual QA across invoice pay, invoice email preview/send in sandbox, invoice PDF, eSignature test send, billing center, portal login, and portal dashboard. Include screenshot comparisons for customer pages before merging visual changes.
