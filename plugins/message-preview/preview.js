/**
 * Message Preview: "Subject - preview text" in the message list.
 *
 * When the IMAP server advertises PREVIEW (RFC 8970) the core already fills
 * message.preview and that is used as is. Otherwise the visible messages are
 * sent in batches to the PluginMessagePreview JSON hook, and the results are
 * cached in localStorage so each message is only fetched once per browser.
 */
(rl => {
	if (!rl || !window.ko) {
		return;
	}

	const
		BATCH = 25,
		STORE = 'tachyon-message-preview-v1',
		STORE_MAX = 3000,
		SEPARATOR = ' - ', // non-breaking, so the leading space survives

		cache = new Map(),   // "folder\0uid" -> text
		pending = new Map(), // "folder\0uid" -> [elements waiting for it]
		queue = new Map(),   // folder -> Set(uid)

		key = (folder, uid) => folder + '\u0000' + uid,

		paint = (el, text) => {
			el.textContent = text ? SEPARATOR + text : '';
		};

	// Restore the cache from earlier visits
	try {
		Object.entries(JSON.parse(localStorage.getItem(STORE) || '{}'))
			.forEach(([k, v]) => cache.set(k, v));
	} catch {
		// No storage available: previews are simply fetched again
	}

	let saveTimer = 0;
	const save = () => {
		clearTimeout(saveTimer);
		saveTimer = setTimeout(() => {
			try {
				localStorage.setItem(STORE, JSON.stringify(
					Object.fromEntries([...cache.entries()].slice(-STORE_MAX))
				));
			} catch {
				// Quota exceeded or storage disabled: keep the in-memory cache only
			}
		}, 1000);
	};

	// One request at a time, one folder at a time, up to BATCH messages each
	let running = false, flushTimer = 0;
	const flush = () => {
		if (running) {
			return;
		}
		const next = queue.entries().next();
		if (next.done) {
			return;
		}
		const [folder, uids] = next.value,
			batch = [...uids].slice(0, BATCH);
		batch.forEach(uid => uids.delete(uid));
		if (!uids.size) {
			queue.delete(folder);
		}

		running = true;
		rl.pluginRemoteRequest((iError, data) => {
			running = false;
			const result = (!iError && data && data.Result) || null;
			batch.forEach(uid => {
				const k = key(folder, uid);
				if (result) {
					const text = result[uid] || '';
					cache.set(k, text);
					(pending.get(k) || []).forEach(el => paint(el, text));
				}
				// On error the waiters are dropped, so the next render retries
				pending.delete(k);
			});
			if (result) {
				save();
			}
			flush();
		}, 'MessagePreview', { folder: folder, uids: batch.join(',') });
	};
	const schedule = () => {
		clearTimeout(flushTimer);
		flushTimer = setTimeout(flush, 250);
	};

	ko.bindingHandlers.messagePreview = {
		init: (el, valueAccessor) => {
			const msg = ko.unwrap(valueAccessor());
			if (!msg || !msg.uid || !msg.folder) {
				return;
			}
			// The server's own RFC 8970 preview, when there is one
			if (msg.preview) {
				paint(el, msg.preview);
				return;
			}
			const k = key(msg.folder, msg.uid);
			if (cache.has(k)) {
				paint(el, cache.get(k));
				return;
			}
			if (pending.has(k)) {
				pending.get(k).push(el);
			} else {
				pending.set(k, [el]);
			}
			if (!queue.has(msg.folder)) {
				queue.set(msg.folder, new Set());
			}
			queue.get(msg.folder).add(msg.uid);
			schedule();
		}
	};

	// Place the preview right after the subject. It has to be a sibling:
	// .subjectParent uses a "text:" binding, which would wipe out anything put
	// inside it. The template has a plain and a grouped list; only one renders.
	const template = document.getElementById('MailMessageList');
	if (template) {
		template.content.querySelectorAll('.subjectParent').forEach(el =>
			el.after(Element.fromHTML('<div class="messagePreview" data-bind="messagePreview: $data"></div>'))
		);
	}

})(window.rl);
