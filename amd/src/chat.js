// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * AI Course Assistant browser interaction module.
 *
 * @module    local_courseaiassistant/chat
 * @copyright 2026 Nellie Deutsch
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

let initialized = false;

export const init = () => {
    if (initialized) {
        return;
    }
    initialized = true;



    function getString(key, fallback) {
        try {
            if (window.M && M.util && typeof M.util.get_string === 'function') {
                var resolved = M.util.get_string(key, 'local_courseaiassistant');
                if (resolved && !/^\[\[.+\]\]$/.test(resolved)) {
                    return resolved;
                }
            }
        } catch (error) {
            // Fall back to English if Moodle strings are not available.
        }
        return fallback;
    }

    function decodeHtml(value) {
        var textarea = document.createElement('textarea');
        textarea.innerHTML = String(value || '');
        return textarea.value;
    }

    function normalizeMessageText(message) {
        return decodeHtml(message)
            .replace(/\r\n?/g, '\n')
            .replace(/^(\d+)\\\.\s+/gm, '$1. ')
            .replace(/^[ \t]*\\?[-_]{3,}[ \t]*$/gm, '')
            .replace(/^[ \t]*\\?#{1,6}[ \t]*/gm, '')
            .replace(/[ \t]+\n/g, '\n')
            .replace(/\n[ \t]+/g, '\n')
            .replace(/\n{3,}/g, '\n\n')
            .trim();
    }

    function readableLinkLabel(url) {
        try {
            var parsed = new URL(url);
            var host = parsed.hostname.replace(/^www\./i, '');
            return host;
        } catch (error) {
            return url;
        }
    }

    function appendInlineLinkedText(container, text) {
        var pattern = /\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)|(https?:\/\/[^\s]+)/g;
        var lastIndex = 0;
        var match;

        while ((match = pattern.exec(text)) !== null) {
            if (match.index > lastIndex) {
                container.appendChild(
                    document.createTextNode(
                        text.substring(lastIndex, match.index)
                    )
                );
            }

            var label = match[1] || '';
            var originalUrl = match[2] || match[3] || '';
            var cleanUrl = originalUrl;
            var trailing = '';

            while (/[.,;!?)]$/.test(cleanUrl)) {
                trailing = cleanUrl.slice(-1) + trailing;
                cleanUrl = cleanUrl.slice(0, -1);
            }

            var link = document.createElement('a');
            link.href = cleanUrl;
            link.textContent =
                label || readableLinkLabel(cleanUrl);
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.className = 'course-ai-link';

            container.appendChild(link);

            if (trailing) {
                container.appendChild(
                    document.createTextNode(trailing)
                );
            }

            lastIndex =
                match.index + match[0].length;
        }

        if (lastIndex < text.length) {
            container.appendChild(
                document.createTextNode(
                    text.substring(lastIndex)
                )
            );
        }
    }

    function appendLinkedText(container, message) {
        var text = normalizeMessageText(message);
        var list = null;

        text.split('\n').forEach(function(line) {
            var bullet = line.match(/^•\s+(.+)$/);

            if (bullet) {
                if (!list) {
                    list = document.createElement('ul');
                    list.className = 'course-ai-response-list';
                    container.appendChild(list);
                }

                var item = document.createElement('li');
                appendInlineLinkedText(item, bullet[1]);
                list.appendChild(item);
                return;
            }

            list = null;

            if (line.trim() === '') {
                return;
            }

            var p = document.createElement('p');
            if (/^\d+\.\s+/.test(line)) {
                p.className = 'course-ai-numbered-heading';
            }
            appendInlineLinkedText(p, line);
            container.appendChild(p);
        });
    }

    function escapeTranscriptHtml(value) {
        var div = document.createElement('div');
        div.textContent = String(value || '');
        return div.innerHTML;
    }

    function transcriptLinkedHtml(message) {
        var holder = document.createElement('div');
        appendLinkedText(holder, message);

        holder.querySelectorAll('a').forEach(function(link) {
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
        });

        return holder.innerHTML;
    }

    function buildCopyTranscript(chatBox) {
        var messages = collectCurrentMessages(chatBox);

        var plain = [];
        var html = [];

        messages.forEach(function(message) {
            var label =
                message.type === 'user'
                    ? 'You'
                    : (
                        chatBox.getAttribute(
                            'data-assistantname'
                        ) ||
                        getString(
                            'pluginname',
                            'AI Course Assistant'
                        )
                    );

            var text =
                normalizeMessageText(message.text);

            plain.push(
                label + ':\n' + text
            );

            html.push(
                '<section class="course-ai-copied-message">' +
                    '<p><strong>' +
                    escapeTranscriptHtml(label) +
                    ':</strong></p>' +
                    transcriptLinkedHtml(text) +
                '</section>'
            );
        });

        return {
            plain: plain.join('\n\n'),
            html:
                '<div class="course-ai-copied-chat">' +
                html.join('<br>') +
                '</div>'
        };
    }

    function legacyClipboardCopy(text) {
        return new Promise(function(resolve, reject) {
            var textarea = document.createElement('textarea');

            textarea.value = String(text || '');
            textarea.setAttribute('readonly', '');
            textarea.setAttribute('aria-hidden', 'true');

            textarea.style.position = 'fixed';
            textarea.style.left = '-9999px';
            textarea.style.top = '0';
            textarea.style.opacity = '0';
            textarea.style.pointerEvents = 'none';

            document.body.appendChild(textarea);

            textarea.focus();
            textarea.select();
            textarea.setSelectionRange(
                0,
                textarea.value.length
            );

            var successful = false;

            try {
                successful =
                    document.execCommand('copy');
            } catch (error) {
                successful = false;
            }

            textarea.remove();

            if (successful) {
                resolve();
            } else {
                reject(
                    new Error(
                        getString(
                            'copyfailed',
                            'The chat could not be copied.'
                        )
                    )
                );
            }
        });
    }

    function copyCurrentConversation(chatBox) {
        var transcript =
            buildCopyTranscript(chatBox);

        if (!transcript.plain) {
            return Promise.reject(
                new Error(
                    getString(
                        'nothingtocopy',
                        'There is no conversation to copy yet.'
                    )
                )
            );
        }

        /*
         * Best option:
         * copy rich HTML and plain text together.
         */
        if (
            window.isSecureContext &&
            navigator.clipboard &&
            typeof navigator.clipboard.write === 'function' &&
            typeof window.ClipboardItem !== 'undefined'
        ) {
            try {
                var item = new ClipboardItem({
                    'text/plain': new Blob(
                        [transcript.plain],
                        {type: 'text/plain'}
                    ),
                    'text/html': new Blob(
                        [transcript.html],
                        {type: 'text/html'}
                    )
                });

                return navigator.clipboard
                    .write([item])
                    .catch(function() {
                        /*
                         * Some browsers expose ClipboardItem but
                         * reject rich clipboard writes. Fall through
                         * to plain clipboard APIs.
                         */
                        if (
                            navigator.clipboard &&
                            typeof navigator.clipboard.writeText === 'function'
                        ) {
                            return navigator.clipboard
                                .writeText(transcript.plain)
                                .catch(function() {
                                    return legacyClipboardCopy(
                                        transcript.plain
                                    );
                                });
                        }

                        return legacyClipboardCopy(
                            transcript.plain
                        );
                    });

            } catch (error) {
                /*
                 * Continue to universal fallback below.
                 */
            }
        }

        /*
         * Standard plain-text clipboard.
         */
        if (
            window.isSecureContext &&
            navigator.clipboard &&
            typeof navigator.clipboard.writeText === 'function'
        ) {
            return navigator.clipboard
                .writeText(transcript.plain)
                .catch(function() {
                    return legacyClipboardCopy(
                        transcript.plain
                    );
                });
        }

        /*
         * Universal browser fallback.
         */
        return legacyClipboardCopy(
            transcript.plain
        );
    }


    function createAvatar(chatBox, type) {
        var avatar = document.createElement('div');
        avatar.className = 'course-ai-message-avatar';

        if (type === 'assistant') {
            var assistantIconUrl = chatBox.getAttribute('data-assistanticonurl') || '';
            if (assistantIconUrl) {
                avatar.classList.add('course-ai-icon-image');
                var assistantImage = document.createElement('img');
                assistantImage.src = assistantIconUrl;
                assistantImage.alt = '';
                avatar.appendChild(assistantImage);
            } else {
                avatar.classList.add('course-ai-icon-default');
                avatar.textContent = '✦';
            }
            avatar.setAttribute('aria-label', chatBox.getAttribute('data-assistantname') || getString('pluginname', 'AI Course Assistant'));
            return avatar;
        }

        var avatarUrl = chatBox.getAttribute('data-useravatar') || '';
        if (avatarUrl) {
            var image = document.createElement('img');
            image.src = avatarUrl;
            image.alt = getString('userprofilepicture', 'Your Moodle profile picture');
            avatar.appendChild(image);
        } else {
            avatar.textContent = '●';
            avatar.setAttribute('aria-label', getString('moodleuser', 'Moodle user'));
        }

        return avatar;
    }

    function addMessage(chatBox, message, type, extraClass) {
        var history = chatBox.querySelector('.course-ai-chat-history');
        if (!history) {
            return null;
        }

        var row = document.createElement('div');
        row.className = 'course-ai-message course-ai-' + type + '-message' + (extraClass ? ' ' + extraClass : '');
        row.setAttribute('data-message-type', type);
        row.setAttribute('data-course-ai-decorated', '1');
        row.setAttribute('data-message-source', String(message || ''));

        var bubble = document.createElement('div');
        bubble.className = 'course-ai-message-bubble';
        appendLinkedText(bubble, message);

        row.appendChild(createAvatar(chatBox, type));
        row.appendChild(bubble);
        if (type === 'assistant' && 'speechSynthesis' in window) {
            var readButton = document.createElement('button');
            readButton.type = 'button';
            readButton.className = 'course-ai-read-button';
            readButton.textContent = '🔊';
            readButton.title = getString('readresponse', 'Read this response aloud');
            readButton.setAttribute('aria-label', getString('readresponse', 'Read this response aloud'));
            readButton.setAttribute('data-text', normalizeMessageText(message));
            row.appendChild(readButton);
        }
        history.appendChild(row);
        history.scrollTop = history.scrollHeight;

        return row;
    }

    /**
     * Prepare displayed assistant text for speech synthesis.
     *
     * Uppercase acronyms are spoken letter by letter:
     * ELT   -> E L T
     * AI    -> A I
     * TESOL -> T E S O L
     *
     * This changes speech only. Displayed text is untouched.
     */
    function prepareTextForSpeech(text) {
        return String(text || '')
            .replace(
                /\bAI\b/gi,
                'A. I.'
            )
            .replace(
                /\b[A-Z]{2,}\b/g,
                function(acronym) {
                    return acronym.split('').join('. ') + '.';
                }
            );
    }

    function getReasoningHistory(chatBox) {
        try {
            var history = JSON.parse(chatBox.dataset.history || '[]');
            return Array.isArray(history) ? history : [];
        } catch (error) {
            return [];
        }
    }

    function setReasoningHistory(chatBox, history) {
        chatBox.dataset.history = JSON.stringify(history.slice(-12));
    }

    function historyRequest(chatBox, action, payload) {
        var endpoint = chatBox.getAttribute('data-historyendpoint');
        if (!endpoint) {
            return Promise.reject(new Error(getString('historyunavailable', 'Chat history is unavailable.')));
        }
        return fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(Object.assign({
                action: action,
                courseid: chatBox.getAttribute('data-courseid'),
                sesskey: chatBox.getAttribute('data-sesskey')
            }, payload || {}))
        }).then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok || data.error) {
                    throw new Error(data.error || getString('historyrequestfailed', 'Chat history request failed.'));
                }
                return data;
            });
        });
    }

    function getSavedChats(chatBox) {
        return historyRequest(chatBox, 'list').then(function (data) {
            return data.conversations || [];
        });
    }

    function saveCurrentConversation(chatBox) {
        var messages = collectCurrentMessages(chatBox);
        var firstUser = messages.find(function (message) { return message.type === 'user'; });
        if (!firstUser) {
            return Promise.resolve(null);
        }
        return historyRequest(chatBox, 'save', {
            id: parseInt(chatBox.dataset.conversationid || '0', 10),
            title: firstUser.text.slice(0, 100) || getString('conversationdefaulttitle', 'Course conversation'),
            messages: messages
        }).then(function (data) {
            chatBox.dataset.conversationid = String(data.id || '');
            rememberActiveConversation(chatBox, data.id || 0);
            notifyConversationUpdated(chatBox, data.id || 0);
            return data.id || null;
        });
    }

    function collectCurrentMessages(chatBox) {
        var messages = [];
        chatBox.querySelectorAll('.course-ai-message:not([data-initial-message="1"])').forEach(function (row) {
            var bubble = row.querySelector('.course-ai-message-bubble');
            if (!bubble) {
                return;
            }

            var type = row.classList.contains('course-ai-user-message') ? 'user' : 'assistant';
            var source = row.getAttribute('data-message-source');
            var context = {};

            try {
                context = JSON.parse(row.getAttribute('data-message-context') || '{}');
            } catch (error) {
                context = {};
            }

            messages.push({
                type: type,
                text: source !== null ? source : bubble.textContent.trim(),
                context: context
            });
        });
        return messages;
    }

    function archiveConversation(chatBox) {
        return saveCurrentConversation(chatBox);
    }

    function clearCurrentConversation(chatBox) {
        chatBox.querySelectorAll('.course-ai-message:not([data-initial-message="1"])').forEach(function (message) {
            message.remove();
        });
        setReasoningHistory(chatBox, []);
        chatBox.dataset.conversationid = '';
        rememberActiveConversation(chatBox, 0);

        var input = chatBox.querySelector('.course-ai-chat-input');
        if (input) {
            input.value = '';
            input.focus();
        }
    }

    function restoreConversation(chatBox, chat) {
        return historyRequest(chatBox, 'get', {id: chat.id}).then(function (data) {
            clearCurrentConversation(chatBox);
            chatBox.dataset.conversationid = String(chat.id);
            rememberActiveConversation(chatBox, chat.id);
            var reasoning = [];
            (data.messages || []).forEach(function (message) {
                var role = message.role === 'assistant' ? 'assistant' : 'user';
                var context = {};

                try {
                    context = JSON.parse(message.contextjson || '{}');
                } catch (error) {
                    context = {};
                }

                var row = addMessage(chatBox, message.message, role);
                if (row) {
                    row.setAttribute('data-message-context', JSON.stringify(context));
                }

                reasoning.push({
                    role: role,
                    content: message.message,
                    scope: 'course',
                    context: context
                });
            });
            setReasoningHistory(chatBox, reasoning);
            var panel = chatBox.querySelector('.course-ai-history-panel');
            if (panel) {
                panel.classList.remove('is-open');
                panel.setAttribute('aria-hidden', 'true');
            }
            return data;
        }).catch(function (error) {
            window.alert(error.message);
            throw error;
        });
    }

    function formatDate(value) {
        var date = new Date(value);
        return Number.isNaN(date.getTime()) ? '' : date.toLocaleString();
    }

    function renderHistory(chatBox) {
        var list = chatBox.querySelector('.course-ai-history-list');
        if (!list) { return; }
        list.innerHTML = '';
        var loading = document.createElement('div');
        loading.className = 'course-ai-history-empty';
        loading.textContent = getString('loadingconversations', 'Loading conversations...');
        list.appendChild(loading);
        getSavedChats(chatBox).then(function (chats) {
            list.innerHTML = '';
            if (!chats.length) {
                var empty = document.createElement('div');
                empty.className = 'course-ai-history-empty';
                empty.textContent = getString('nopreviousconversations', 'No previous conversations yet.');
                list.appendChild(empty);
                return;
            }
            chats.forEach(function (chat) {
                var item = document.createElement('div');
                item.className = 'course-ai-history-item';
                var open = document.createElement('button');
                open.type = 'button';
                open.className = 'course-ai-history-open';
                open.innerHTML = '<span class="course-ai-history-title"></span><span class="course-ai-history-date"></span>';
                open.querySelector('.course-ai-history-title').textContent = chat.title || getString('conversationdefaulttitle', 'Course conversation');
                open.querySelector('.course-ai-history-date').textContent = formatDate(chat.timemodified * 1000);
                open.addEventListener('click', function () { restoreConversation(chatBox, chat); });

                var downloads = document.createElement('div');
                downloads.className = 'course-ai-history-download-group';

                var downloadTxt = document.createElement('a');
                downloadTxt.className = 'course-ai-history-download';
                downloadTxt.textContent = 'TXT';
                downloadTxt.title = getString('downloadtxt', 'Download as TXT');
                downloadTxt.href = chatBox.getAttribute('data-downloadendpoint') + '?courseid=' + encodeURIComponent(chatBox.getAttribute('data-courseid')) + '&id=' + encodeURIComponent(chat.id) + '&format=txt&sesskey=' + encodeURIComponent(chatBox.getAttribute('data-sesskey'));

                var downloadHtml = document.createElement('a');
                downloadHtml.className = 'course-ai-history-download';
                downloadHtml.textContent = 'HTML';
                downloadHtml.title = getString('downloadhtml', 'Download as HTML');
                downloadHtml.href = chatBox.getAttribute('data-downloadendpoint') + '?courseid=' + encodeURIComponent(chatBox.getAttribute('data-courseid')) + '&id=' + encodeURIComponent(chat.id) + '&format=html&sesskey=' + encodeURIComponent(chatBox.getAttribute('data-sesskey'));

                downloads.appendChild(downloadTxt);
                downloads.appendChild(downloadHtml);

                var rename = document.createElement('button');
                rename.type = 'button';
                rename.className = 'course-ai-history-rename';
                rename.textContent = '✎';
                rename.title = getString('renameconversation', 'Rename conversation');
                rename.addEventListener('click', function () {
                    var title = window.prompt(getString('renamethisconversation', 'Rename this conversation'), chat.title || getString('conversationdefaulttitle', 'Course conversation'));
                    if (title) {
                        historyRequest(chatBox, 'rename', {id: chat.id, title: title}).then(function () { renderHistory(chatBox); });
                    }
                });

                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'course-ai-history-delete';
                remove.textContent = '×';
                remove.title = getString('deletethisconversation', 'Delete this conversation');
                remove.addEventListener('click', function () {
                    historyRequest(chatBox, 'delete', {id: chat.id}).then(function () { renderHistory(chatBox); });
                });
                item.appendChild(open);
                item.appendChild(downloads);
                item.appendChild(rename);
                item.appendChild(remove);
                list.appendChild(item);
            });
        }).catch(function (error) {
            list.innerHTML = '';
            var empty = document.createElement('div');
            empty.className = 'course-ai-history-empty';
            empty.textContent = error.message;
            list.appendChild(empty);
        });
    }

    function setLauncherState(chatBox, open) {
        chatBox.classList.toggle('course-ai-open', open);
        chatBox.querySelectorAll('.course-ai-launcher-button').forEach(function(button) {
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        if (open) {
            var input = chatBox.querySelector('.course-ai-chat-input');
            if (input) {
                window.setTimeout(function() { input.focus(); }, 50);
            }
        }
    }

    function preparePlacement(chatBox) {
        var placement = chatBox.getAttribute('data-placement') || 'button';
        if (placement !== 'button') {
            return;
        }

        var source = chatBox.querySelector('.course-ai-launcher-button');
        if (!source || document.querySelector('[data-course-ai-header-launcher="' + chatBox.id + '"]')) {
            return;
        }

        var header = document.querySelector('.page-header-headings') ||
            document.querySelector('#page-header .d-flex') ||
            document.querySelector('#page-header');
        if (!header) {
            return;
        }

        var clone = source.cloneNode(true);
        clone.setAttribute('data-course-ai-header-launcher', chatBox.id);
        clone.classList.add('course-ai-header-launcher');
        clone.addEventListener('click', function(event) {
            event.preventDefault();
            setLauncherState(chatBox, !chatBox.classList.contains('course-ai-open'));
        });
        header.appendChild(clone);
        source.classList.add('course-ai-launcher-fallback-hidden');
    }

    document.querySelectorAll('.course-ai-chat-box').forEach(preparePlacement);

    function activeConversationKey(chatBox) {
        return 'local_courseaiassistant_active_' +
            String(chatBox.getAttribute('data-userid') || '0') + '_' +
            String(chatBox.getAttribute('data-courseid') || '0');
    }

    function rememberActiveConversation(chatBox, id) {
        try {
            var key = activeConversationKey(chatBox);
            if (id && parseInt(id, 10) > 0) {
                window.localStorage.setItem(key, String(id));
            } else {
                window.localStorage.removeItem(key);
            }
        } catch (error) {
            // Conversation continuity still works through URL parameters when storage is unavailable.
        }
    }

    function recalledActiveConversation(chatBox) {
        try {
            return parseInt(window.localStorage.getItem(activeConversationKey(chatBox)) || '0', 10);
        } catch (error) {
            return 0;
        }
    }

    function addUrlParam(url, key, value) {
        var resolved = new URL(url, window.location.href);
        if (value !== null && value !== undefined && String(value) !== '') {
            resolved.searchParams.set(key, String(value));
        }
        return resolved.toString();
    }

    function conversationSyncKey(chatBox) {
        return 'local_courseaiassistant_sync_' +
            chatBox.getAttribute('data-userid') + '_' +
            chatBox.getAttribute('data-courseid');
    }

    function notifyConversationUpdated(chatBox, id) {
        if (!id) {
            return;
        }
        try {
            window.localStorage.setItem(conversationSyncKey(chatBox), JSON.stringify({
                id: parseInt(id, 10),
                time: Date.now()
            }));
        } catch (error) {
            // Cross-tab sync is optional; server history remains authoritative.
        }
    }

    function openFullPage(chatBox, href) {
        var target = href || chatBox.getAttribute('data-fullpageurl');
        if (!target) {
            return;
        }
        saveCurrentConversation(chatBox).catch(function () { return null; }).then(function (id) {
            var url = addUrlParam(target, 'returnurl', window.location.pathname + window.location.search + window.location.hash);
            if (id) {
                url = addUrlParam(url, 'conversationid', id);
            }
            var opened = window.open(url, '_blank');
            if (opened) {
                opened.focus();
            } else {
                window.location.href = url;
            }
        });
    }

    function returnToCourse(chatBox, returnButton) {
        var target = returnButton.getAttribute('data-returnurl') || chatBox.getAttribute('data-returnurl') || '/course/view.php?id=' + encodeURIComponent(chatBox.getAttribute('data-courseid'));
        saveCurrentConversation(chatBox).catch(function () { return null; }).then(function (id) {
            if (id) {
                notifyConversationUpdated(chatBox, id);
            }

            // When the full-page assistant was opened from a course page,
            // keep the course tab open, focus it, and close this assistant tab.
            if (window.opener && !window.opener.closed) {
                try {
                    window.opener.focus();
                    window.close();
                    return;
                } catch (error) {
                    // Fall through to normal navigation if the browser blocks access.
                }
            }

            var url = target;
            if (id) {
                url = addUrlParam(url, 'courseaiconversationid', id);
            }
            window.location.href = url;
        });
    }

    document.querySelectorAll('.course-ai-chat-box').forEach(function (chatBox) {
        var id = parseInt(chatBox.getAttribute('data-conversationid') || '0', 10);
        if (id <= 0) {
            id = recalledActiveConversation(chatBox);
        }
        if (id > 0) {
            restoreConversation(chatBox, {id: id});
        }
    });

    function sendMessage(chatBox) {
        var input = chatBox.querySelector('.course-ai-chat-input');
        var sendButton = chatBox.querySelector('.course-ai-send-button');
        if (!input || !sendButton) {
            return;
        }

        var message = input.value.trim();
        if (!message || sendButton.disabled) {
            input.focus();
            return;
        }

        var conversation = getReasoningHistory(chatBox);
        addMessage(chatBox, message, 'user');
        input.value = '';
        input.disabled = true;
        sendButton.disabled = true;
        sendButton.textContent = getString('sending', 'Sending...');

        var contextPageUrl = window.location.href;
        var contextPageTitle = document.title;
        if (chatBox.getAttribute('data-popup') === '1' && window.opener && !window.opener.closed) {
            try {
                contextPageUrl = window.opener.location.href;
                contextPageTitle = window.opener.document.title;
            } catch (error) {
                // The pop-out can still provide course-level help when opener details are unavailable.
            }
        } else if (chatBox.getAttribute('data-popup') === '1') {
            var popupContext = chatBox.getAttribute('data-returnurl');
            if (popupContext) {
                contextPageUrl = new URL(popupContext, window.location.origin).toString();
            }
        }

        fetch(chatBox.getAttribute('data-endpoint'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                message: message,
                courseid: chatBox.getAttribute('data-courseid'),
                sesskey: chatBox.getAttribute('data-sesskey'),
                history: conversation,
                pageurl: contextPageUrl,
                pagetitle: contextPageTitle
            })
        })
        .then(function (response) {
            return response.text().then(function (text) {
                var data;
                try {
                    data = JSON.parse(text);
                } catch (error) {
                    throw new Error(getString('unexpectedresponse', 'The assistant returned an unexpected response. Please try again.'));
                }
                if (!response.ok) {
                    throw new Error(data.response || 'HTTP status ' + response.status);
                }
                return data;
            });
        })
        .then(function (data) {
            var reply = data.response || getString('noresponse', 'No response received.');
            var assistantContext = data.context || {};
            var replyRow = addMessage(chatBox, reply, 'assistant');

            if (replyRow) {
                replyRow.setAttribute(
                    'data-message-context',
                    JSON.stringify(assistantContext)
                );
            }

            conversation.push({
                role: 'user',
                content: message,
                scope: data.scope === 'course' ? 'course' : 'local',
                context: {}
            });

            conversation.push({
                role: 'assistant',
                content: reply,
                scope: data.scope === 'course' ? 'course' : 'local',
                context: assistantContext
            });

            setReasoningHistory(chatBox, conversation);
            saveCurrentConversation(chatBox).catch(function () {});
        })
        .catch(function (error) {
            addMessage(chatBox, error.message || getString('serviceunavailable', 'Could not contact the AI service.'), 'assistant', 'course-ai-error');
        })
        .finally(function () {
            input.disabled = false;
            sendButton.disabled = false;
            sendButton.textContent = getString('send', 'Send');
            input.focus();
        });
    }

    document.addEventListener('click', function (event) {
        var popupDockButton = event.target.closest('.course-ai-popup-dock-button');
        if (popupDockButton) {
            event.preventDefault();
            var popupBox = popupDockButton.closest('.course-ai-chat-box');
            saveCurrentConversation(popupBox).catch(function() { return null; }).then(function(id) {
                if (id) {
                    notifyConversationUpdated(popupBox, id);
                }
                if (window.opener && !window.opener.closed) {
                    window.opener.postMessage({
                        type: 'local_courseaiassistant_dock',
                        courseid: popupBox.getAttribute('data-courseid'),
                        conversationid: id || 0
                    }, window.location.origin);
                    window.opener.focus();
                    window.close();
                } else {
                    try {
                        window.localStorage.setItem(
                            'local_courseaiassistant_dock_' + popupBox.getAttribute('data-courseid'),
                            JSON.stringify({time: Date.now(), conversationid: id || 0})
                        );
                    } catch (error) {
                        // The pop-out can still be closed if storage is unavailable.
                    }
                    window.close();
                }
            });
            return;
        }

        var fullPageButton = event.target.closest('.course-ai-open-fullpage-button');
        if (fullPageButton) {
            event.preventDefault();
            var fullPageBox = fullPageButton.closest('.course-ai-chat-box');
            if (fullPageBox) {
                openFullPage(fullPageBox, fullPageButton.href);
            }
            return;
        }

        var returnButton = event.target.closest('.course-ai-return-button');
        if (returnButton) {
            event.preventDefault();
            var returnBox = document.querySelector('.course-ai-fullpage-view .course-ai-chat-box');
            if (returnBox) {
                returnToCourse(returnBox, returnButton);
            } else {
                window.location.href = returnButton.href;
            }
            return;
        }

        var launcher = event.target.closest('.course-ai-launcher-button');
        if (launcher && !launcher.hasAttribute('data-course-ai-header-launcher')) {
            event.preventDefault();
            var launcherBox = launcher.closest('.course-ai-chat-box');
            if (launcherBox) {
                setLauncherState(launcherBox, !launcherBox.classList.contains('course-ai-open'));
            }
            return;
        }

        var quick = event.target.closest('.course-ai-quick-action');
        if (quick) {
            event.preventDefault();
            var quickBox = quick.closest('.course-ai-chat-box');
            var quickInput = quickBox ? quickBox.querySelector('.course-ai-chat-input') : null;
            if (quickInput) {
                quickInput.value = quick.getAttribute('data-prompt') || '';
                sendMessage(quickBox);
            }
            return;
        }

        var sendButton = event.target.closest('.course-ai-send-button');
        if (sendButton) {
            event.preventDefault();
            sendMessage(sendButton.closest('.course-ai-chat-box'));
            return;
        }

        var newChatButton = event.target.closest('.course-ai-new-chat-button');
        if (newChatButton) {
            event.preventDefault();
            var newChatBox = newChatButton.closest('.course-ai-chat-box');
            archiveConversation(newChatBox).finally(function () { clearCurrentConversation(newChatBox); });
            return;
        }

        var collapse = event.target.closest('.course-ai-collapse-button');
        if (collapse) {
            event.preventDefault();
            var collapseBox = collapse.closest('.course-ai-chat-box');
            var collapsed = collapseBox.classList.toggle('course-ai-collapsed');
            collapse.textContent = collapsed ? '+' : '−';
            return;
        }

        var copyChat = event.target.closest('.course-ai-copy-chat-button');
        if (copyChat) {
            event.preventDefault();

            var copyBox =
                copyChat.closest('.course-ai-chat-box');

            if (!copyBox) {
                return;
            }

            copyCurrentConversation(copyBox)
                .then(function() {
                    /*
                     * Brief accessible confirmation without adding
                     * permanent text or hover labels to the header.
                     */
                    var originalLabel =
                        copyChat.getAttribute('aria-label') ||
                        getString('copychat', 'Copy chat');

                    copyChat.setAttribute(
                        'aria-label',
                        getString(
                            'chatcopied',
                            'Chat copied.'
                        )
                    );

                    copyChat.classList.add(
                        'course-ai-copy-success'
                    );

                    window.setTimeout(function() {
                        copyChat.setAttribute(
                            'aria-label',
                            originalLabel
                        );

                        copyChat.classList.remove(
                            'course-ai-copy-success'
                        );
                    }, 1200);
                })
                .catch(function(error) {
                    window.alert(
                        error.message ||
                        getString(
                            'copyfailed',
                            'The chat could not be copied.'
                        )
                    );
                });

            return;
        }

        var downloadCurrent = event.target.closest('.course-ai-download-current-button');
        if (downloadCurrent) {
            event.preventDefault();
            var downloadBox = downloadCurrent.closest('.course-ai-chat-box');
            saveCurrentConversation(downloadBox).then(function (id) {
                if (!id) {
                    window.alert(getString('askbeforedownload', 'Ask at least one question before downloading the chat.'));
                    return;
                }
                var endpoint = downloadBox.getAttribute('data-downloadendpoint');
                var url = endpoint + '?courseid=' + encodeURIComponent(downloadBox.getAttribute('data-courseid')) + '&id=' + encodeURIComponent(id) + '&format=txt&sesskey=' + encodeURIComponent(downloadBox.getAttribute('data-sesskey'));
                window.location.href = url;
            }).catch(function (error) {
                window.alert(error.message || getString('downloadfailed', 'The chat could not be downloaded.'));
            });
            return;
        }

        var historyButton = event.target.closest('.course-ai-history-button');
        if (historyButton) {
            event.preventDefault();
            var historyBox = historyButton.closest('.course-ai-chat-box');
            var panel = historyBox.querySelector('.course-ai-history-panel');
            renderHistory(historyBox);
            panel.classList.toggle('is-open');
            panel.setAttribute('aria-hidden', panel.classList.contains('is-open') ? 'false' : 'true');
            return;
        }

        var historyClose = event.target.closest('.course-ai-history-close');
        if (historyClose) {
            event.preventDefault();
            var closePanel = historyClose.closest('.course-ai-history-panel');
            closePanel.classList.remove('is-open');
            closePanel.setAttribute('aria-hidden', 'true');
            return;
        }

        var readButton = event.target.closest('.course-ai-read-button');
        if (readButton) {
            event.preventDefault();

            if (!('speechSynthesis' in window) || !('SpeechSynthesisUtterance' in window)) {
                return;
            }

            if (readButton.getAttribute('data-speaking') === '1') {
                window.speechSynthesis.cancel();
                readButton.setAttribute('data-speaking', '0');
                readButton.textContent = '🔊';
                readButton.title = getString('readaloud', 'Read aloud');
                readButton.setAttribute('aria-label', getString('readaloud', 'Read aloud'));
                return;
            }

            window.speechSynthesis.cancel();

            var utterance = new SpeechSynthesisUtterance(
                prepareTextForSpeech(
                    readButton.getAttribute('data-text') || ''
                )
            );

            utterance.lang = document.documentElement.lang || 'en';

            readButton.setAttribute('data-speaking', '1');
            readButton.textContent = '⏹';
            readButton.title = getString('stopreading', 'Stop reading');
            readButton.setAttribute('aria-label', getString('stopreading', 'Stop reading'));

            var resetReadButton = function () {
                readButton.setAttribute('data-speaking', '0');
                readButton.textContent = '🔊';
                readButton.title = getString('readaloud', 'Read aloud');
                readButton.setAttribute('aria-label', getString('readaloud', 'Read aloud'));
            };

            utterance.onend = resetReadButton;
            utterance.onerror = resetReadButton;

            window.speechSynthesis.speak(utterance);
            return;
        }

        var micButton = event.target.closest('.course-ai-mic-button');
        if (micButton) {
            event.preventDefault();
            var micBox = micButton.closest('.course-ai-chat-box');
            var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (!SpeechRecognition) {
                window.alert(getString('speechnotsupported', 'Speech input is not supported in this browser.'));
                return;
            }
            var recognition = new SpeechRecognition();
            recognition.lang = document.documentElement.lang || 'en-US';
            recognition.interimResults = false;
            micButton.disabled = true;
            micButton.textContent = '…';
            recognition.onresult = function (result) {
                var input = micBox.querySelector('.course-ai-chat-input');
                input.value = result.results[0][0].transcript;
                input.focus();
            };
            recognition.onerror = function () { window.alert(getString('speechunderstandfailed', 'I could not understand the speech. Please try again.')); };
            recognition.onend = function () { micButton.disabled = false; micButton.textContent = '🎤'; };
            recognition.start();
            return;
        }

        var clearAll = event.target.closest('.course-ai-history-clear-all');
        if (clearAll) {
            event.preventDefault();
            var clearBox = clearAll.closest('.course-ai-chat-box');
            if (window.confirm(getString('deleteallconfirm', 'Delete all saved conversations for this course?'))) {
                historyRequest(clearBox, 'clear').then(function () { renderHistory(clearBox); });
            }
        }
    }, true);

    window.addEventListener('storage', function (event) {
        if (!event.key || !event.newValue) {
            return;
        }
        document.querySelectorAll('.course-ai-chat-box').forEach(function (chatBox) {
            if (event.key !== conversationSyncKey(chatBox)) {
                return;
            }
            try {
                var payload = JSON.parse(event.newValue);
                var id = parseInt(payload.id || '0', 10);
                if (id > 0 && String(id) !== String(chatBox.dataset.conversationid || '')) {
                    restoreConversation(chatBox, {id: id});
                } else if (id > 0) {
                    // Refresh the active conversation because another tab may have added messages.
                    restoreConversation(chatBox, {id: id});
                }
            } catch (error) {
                // Ignore malformed sync notifications.
            }
        });
    });


    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' || event.shiftKey || !event.target.classList.contains('course-ai-chat-input')) {
            return;
        }
        event.preventDefault();
        sendMessage(event.target.closest('.course-ai-chat-box'));
    }, true);

};
