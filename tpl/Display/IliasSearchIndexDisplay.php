<?php // tpl/Display/IliasSearchIndexDisplay.php ?>

<div class="mbis-search">
	<div class="mbis-card">
		<div class="mbis-headline">Search</div>

		<div class="mbis-field">
			<input id="mbis-q" type="text" placeholder="Type to search…" autocomplete="off" />
			<div class="mbis-hint">
				<span class="mono">Min <?php echo (int)$this->_['minChars']; ?> chars</span>
				<span id="mbis-status" class="mbis-status">–</span>
			</div>
		</div>

		<div id="mbis-results" class="mbis-results" style="display:none"></div>
	</div>
</div>

<style>
.mbis-search {
	max-width: 820px;
	margin: 18px auto;
	font-family: Arial, sans-serif;
	color: #333;
}

.mbis-card {
	background: #fff;
	border: 1px solid #d6d6d6;
	border-radius: 10px;
	padding: 16px;
	box-shadow: 0 1px 0 rgba(0,0,0,0.03);
}

.mbis-headline {
	font-size: 16px;
	font-weight: 700;
	margin-bottom: 10px;
}

.mbis-field input {
	width: 100%;
	box-sizing: border-box;
	padding: 12px 12px;
	border: 1px solid #cfcfcf;
	border-radius: 10px;
	font-size: 14px;
	outline: none;
	background: #fcfcfc;
}

.mbis-field input:focus {
	border-color: #8aa7d7;
	box-shadow: 0 0 0 3px rgba(138,167,215,0.25);
	background: #fff;
}

.mbis-hint {
	display: flex;
	justify-content: space-between;
	margin-top: 8px;
	font-size: 12px;
	color: #666;
}

.mbis-status {
	font-style: italic;
}

.mono {
	font-family: Consolas, monospace;
}

.mbis-results {
	margin-top: 12px;
	border-top: 1px solid #eee;
	padding-top: 12px;
}

.mbis-item {
	padding: 10px 0;
	border-bottom: 1px solid #f0f0f0;
}

.mbis-item:last-child {
	border-bottom: 0;
}

.mbis-title {
	font-size: 14px;
	font-weight: 700;
	margin: 0 0 4px 0;
}

.mbis-desc {
	font-size: 13px;
	color: #555;
	margin: 0 0 6px 0;
	line-height: 1.35;
}

.mbis-link a {
	font-size: 12px;
	color: #2f5fb3;
	text-decoration: none;
}

.mbis-link a:hover {
	text-decoration: underline;
}

.mbis-empty {
	padding: 10px 0;
	color: #777;
	font-style: italic;
}
</style>

<script>
const MBIS_ENDPOINT = <?php echo json_encode((string)$this->_['endpoint']); ?>;
const MBIS_MIN_CHARS = <?php echo (int)$this->_['minChars']; ?>;
const MBIS_MAX_RESULTS = <?php echo (int)$this->_['maxResults']; ?>;

const mbisQ = document.getElementById("mbis-q");
const mbisResults = document.getElementById("mbis-results");
const mbisStatus = document.getElementById("mbis-status");

let mbisTimer = null;
let mbisLastQuery = "";

function mbisEsc(s) {
	return String(s ?? "").replace(/[&<>"']/g, c => ({
		"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;"
	}[c]));
}

function mbisTrimDesc(s, maxLen = 220) {
	s = String(s ?? "").replace(/\s+/g, " ").trim();
	if (!s) return "";
	if (s.length <= maxLen) return s;
	return s.substring(0, maxLen - 1) + "…";
}

function mbisSetStatus(t) {
	mbisStatus.textContent = t || "–";
}

function mbisSetResultsVisible(v) {
	mbisResults.style.display = v ? "block" : "none";
}

function mbisRender(items) {
	mbisResults.innerHTML = "";

	if (!Array.isArray(items) || items.length === 0) {
		mbisResults.innerHTML = "<div class='mbis-empty'>No results.</div>";
		mbisSetResultsVisible(true);
		return;
	}

	for (const it of items.slice(0, MBIS_MAX_RESULTS)) {
		const title = it.title ? it.title : "(no title)";
		const desc = mbisTrimDesc(it.description, 220);
		const link = it.direct_link || "";

		const html =
			"<div class='mbis-item'>" +
				"<div class='mbis-title'>" + mbisEsc(title) + "</div>" +
				(desc ? "<div class='mbis-desc'>" + mbisEsc(desc) + "</div>" : "") +
				(link ? "<div class='mbis-link'><a href='" + mbisEsc(link) + "' target='_blank' rel='noopener'>Open</a></div>" : "") +
			"</div>";

		mbisResults.insertAdjacentHTML("beforeend", html);
	}

	mbisSetResultsVisible(true);
}

async function mbisSearchNow(q) {
	q = String(q ?? "").trim();

	if (q.length < MBIS_MIN_CHARS) {
		mbisLastQuery = q;
		mbisSetStatus("–");
		mbisSetResultsVisible(false);
		return;
	}

	mbisLastQuery = q;
	mbisSetStatus("Searching…");

	try {
		const url = MBIS_ENDPOINT + "search&q=" + encodeURIComponent(q);
		const res = await fetch(url, { method: "GET", headers: { "Accept": "application/json" } });
		const json = await res.json();

		if (!json || json.status !== "ok") {
			mbisSetStatus("Error");
			mbisRender([]);
			return;
		}

		const items = (json.data && json.data.items) ? json.data.items : [];
		mbisSetStatus(items.length ? (items.length + " result(s)") : "No results");
		mbisRender(items);

	} catch (e) {
		mbisSetStatus("Request failed");
		mbisRender([]);
	}
}

function mbisDebouncedSearch() {
	const q = mbisQ.value || "";

	if (mbisTimer) {
		clearTimeout(mbisTimer);
		mbisTimer = null;
	}

	mbisTimer = setTimeout(() => {
		mbisSearchNow(q);
	}, 500);
}

mbisQ.addEventListener("input", () => {
	mbisDebouncedSearch();
});

mbisQ.addEventListener("keydown", (e) => {
	if (e.key === "Escape") {
		mbisQ.value = "";
		mbisSetStatus("–");
		mbisSetResultsVisible(false);
	}
});
</script>

