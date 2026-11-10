=== PrivacyPilot ===
Contributors: andreaskasper
Tags: privacy policy, cookie banner, gdpr, ccpa, consent
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.9.261111
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Privacy policy, cookie policy, terms and imprint from a questionnaire and a centrally maintained clause library, plus a consent banner with script blocking. US and EU/UK, English and German.

== Description ==

PrivacyPilot replaces hosted policy services such as Termageddon on goo1-managed websites.

* **Documents** – Privacy Policy, Cookie Policy, Terms of Use, Your Privacy Choices (with a request form) and Imprint, in English and German. Assembled from clauses whose conditions match the questionnaire answers.
* **Central clause library** – maintained in the GitHub repository, distributed as an Ed25519-signed feed. Every site checks daily; a copy ships with each plugin version as fallback.
* **Approval workflow** – editorial changes are published automatically, material changes wait for approval (with preview and clause diff). Optional automatic publication on the effective date of a legal change.
* **Hub mode** – one site keeps the answers per legal entity and approves; connected sites follow automatically.
* **Consent banner** – opt-in for EU/EEA/UK/CH, opt-out with "Do Not Sell or Share" for the US, by country when Cloudflare tells us the country, otherwise opt-in. Global Privacy Control, Google Consent Mode v2, accessible dialog.
* **Blocking** – known scripts (Google Analytics, Tag Manager, Meta Pixel, …) and embeds (YouTube, Vimeo, Google Maps, …) are blocked until consent; works with page caches. Elementor video widgets supported.
* **Scanner and audit** – finds services on the site's own pages, compares them with the questionnaire and checks which cookies are set before consent.
* **Site Health** – status tests and an info section.
* **Consent log without IP addresses** and a request inbox with deadlines.

PrivacyPilot is not legal advice. The site owner is responsible for the answers in the questionnaire.

== Installation ==

1. Upload the ZIP under Plugins → Add New → Upload Plugin and activate it.
2. Settings → PrivacyPilot: answer the questionnaire, create the pages, review and approve the documents.
3. Switch on the banner when the site is ready.

Updates are delivered from GitHub and appear under Dashboard → Updates.

== Changelog ==

= 0.9.261111 =
* Elementor section and container background videos (YouTube, Vimeo) are blocked until consent; the section shows its static background meanwhile.
* Services can be marked as strictly necessary for a site (below the services question), e.g. reCAPTCHA against form spam: they load without consent and the documents list them as necessary.
* Forms with Google reCAPTCHA show a notice with an "Allow reCAPTCHA" button while reCAPTCHA has no consent, and are not sent until it is allowed (instead of failing silently).

= 0.9.261110 =
* Block helpers services_table and services_list accept several groups (necessary,preferences), so the privacy policy shows one table for other services.
* New placeholder entity.address_line (address in one line) for sentences such as the introduction of the Terms of Use.
* Library feeds can require a minimum plugin version (requires_plugin); older plugins keep their library until they are updated.
* Library: Font Awesome and Zelle in the service catalogue; competition details only when the site runs competitions.

= 0.9.261109 =
* First version: documents EN/DE from the clause library, questionnaire, approval workflow, hub/client, consent banner with geo rules, GPC and Consent Mode v2, script and embed blocking, scanner, cookie audit, request form, consent log, Site Health tests, Termageddon migration.
* Admin in English and German.
* Signed library feed (Ed25519, key 1).
* Warnings when answers contradict the services in use (e.g. an advertising pixel without "sharing").
* Asset URLs carry the file time, so CDNs never serve an outdated banner script.
