=== Automind — AI Chatbot with Local RAG for WordPress ===
Contributors: undercode
Tags: ai, chatbot, openai, rag, knowledge base, support, assistant, faq
Requires at least: 6.4
Tested up to: 6.6
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI chatbot for WordPress with a local knowledge base (RAG). Fast, privacy‑friendly, plug‑and‑play.

== Description ==

Automind adds a plug‑and‑play AI chatbot to your site, powered by OpenAI and your own content (RAG).
Perfect for company sites, blogs, and shops — answer from your posts/pages and manual Q&A.

Highlights:
- Chat UI: shortcode [automind], floating popup, mobile friendly, live streaming (SSE)
- Local RAG: index posts/pages and manual Q&A (.txt/.md), categories filter, FULLTEXT prefilter + vector search
- Strict/Soft modes: Strict answers only from your content; Soft allows safe general info when context is missing
- Admin UX: models, temperature/tokens, logs with retention, popup settings
- Privacy & security: logs off by default, IP hashed (SHA‑256 + salt), nonces, same-origin CORS, rate limiting
- No frameworks: clean PHP + WP REST + vanilla JS/CSS; works on typical shared hosting

What’s inside:
- Chat (shortcode + popup), SSE streaming with watchdog and REST fallback
- RAG: post/page reindex, manual import (.txt/.md), chunk size/overlap, topK, context limit
- History (optional): store last N Q&A pairs for quick review
- Logs: metadata (hashed IP, question, tokens, model, status), retention settings
- i18n: English (US), Polish (PL), Chinese Simplified (zh_CN), Hindi (hi_IN), Spanish (es_ES), French (fr_FR)

Recommended defaults:
- Model: gpt‑4o‑mini; Temperature: 0.3; Max tokens: 512–768
- RAG: ON, Soft mode, topK=5, context=2048
- Logs: ON, retention 14 days
- Popup: ON (bottom-right)

== Installation ==

1. Upload the plugin to /wp-content/plugins/automind/ or install via WP Admin → Plugins.
2. Activate the plugin.
3. Add your OpenAI API key in wp-config.php or in Automind → Settings.
4. Turn on RAG and index your content (Automind → RAG).
5. Place [automind] on a page or enable the floating popup in Settings.

Shortcode:
- [automind] — renders the chat widget.
- Attributes (optional): bot="codi" theme="auto".

== Frequently Asked Questions ==

= Does it work on shared hosting? =
Yes. It’s pure PHP + WP REST + vanilla JS. For SSE streaming, exclude /wp-json/automind/v1/chat/stream from cache and ensure the server honors X‑Accel‑Buffering: no.

= Which models are supported? =
Any OpenAI chat model. We recommend gpt‑4o‑mini for cost/performance.

= What is RAG? =
Retrieval‑Augmented Generation. The assistant uses your indexed content (posts/pages/manual Q&A) to answer. In Strict mode it won’t answer if there’s no relevant context.

= Where are logs stored? =
In a local WP table. Logs are optional (off by default). We store metadata only (hashed IP, user agent, question, tokens, model, status). We do not store assistant replies unless you enable History (optional).

= Can I show the chat as a popup? =
Yes. Enable the popup in Automind → Settings. A floating bubble appears on the site.

= How do I index only specific posts? =
Use category filters in Automind → RAG. You can reindex posts/pages and include only selected categories.

= Why don’t I see “Sources” under answers? =
In 1.0.0 sources are disabled by default for cleaner UX. You still get RAG context internally. We may add a toggle later.

== GDPR / Privacy ==

- The plugin connects to OpenAI’s API to generate answers and embeddings.
- Logs are disabled by default. When enabled, we store metadata only; IPs are hashed (SHA‑256 + salt). Replies are not stored unless you enable History.
- You can clear the RAG index, logs, and history at any time from the admin panel.
- Please review your own privacy policy to reflect use of AI services.

== Security ==

- Nonces (X‑WP‑Nonce), same‑origin CORS
- Optional Bearer token for external front‑ends
- Rate limiting: 1 req/s and 60/min
- Input length limits and sanitization
- SSE watchdog fallback to REST

== Performance ==

- Live streaming (SSE) with watchdog and fallback
- FULLTEXT prefilter + dot product on normalized vectors
- Minimal assets (no build steps)

== Screenshots ==

1. Settings — OpenAI, security, logs, popup
2. Chatbot — bot name, greeting, system prompt, response language, RAG options
3. RAG — sources (post/page/categories), chunking, reindex, manual import
4. Popup — floating bubble and chat panel
5. Logs — recent conversations (metadata only)
6. History — recent Q&A (optional)

== Changelog ==

= 1.0.0 =
- Initial public release: chat + SSE streaming, RAG (posts/pages/manual Q&A), logs, history (optional), popup, i18n.

== Upgrade Notice ==
1.0.0 — First stable release.