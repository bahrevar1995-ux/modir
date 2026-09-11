(function () {
    'use strict';

    if (typeof ModirChat === 'undefined') {
        return;
    }

    var root     = document.getElementById('modir-chat-root');
    var bubble   = document.getElementById('modir-chat-bubble');
    var panel    = document.getElementById('modir-chat-panel');
    var closeBtn = document.getElementById('modir-chat-close');
    var resetBtn = document.getElementById('modir-chat-reset');
    var msgsBox  = document.getElementById('modir-chat-messages');
    var form     = document.getElementById('modir-chat-form');
    var input    = document.getElementById('modir-chat-input');

    if (!root || !bubble || !panel) {
        return;
    }

    var STORAGE_ID_KEY   = 'modir_chat_conversation_id';
    var STORAGE_MSGS_KEY = 'modir_chat_messages';
    var conversationId   = '';
    var isOpen           = false;
    var isSending         = false;

    function getConversationId() {
        if (conversationId) {
            return conversationId;
        }
        try {
            conversationId = window.localStorage.getItem(STORAGE_ID_KEY) || '';
        } catch (e) {
            conversationId = '';
        }
        return conversationId;
    }

    function setConversationId(id) {
        conversationId = id;
        try {
            window.localStorage.setItem(STORAGE_ID_KEY, id);
        } catch (e) {
            /* ignore */
        }
    }

    function loadStoredMessages() {
        try {
            var raw = window.localStorage.getItem(STORAGE_MSGS_KEY);
            return raw ? JSON.parse(raw) : [];
        } catch (e) {
            return [];
        }
    }

    function saveStoredMessages(list) {
        try {
            window.localStorage.setItem(STORAGE_MSGS_KEY, JSON.stringify(list.slice(-40)));
        } catch (e) {
            /* ignore */
        }
    }

    function appendMessage(role, text, persist) {
        var div = document.createElement('div');
        div.className = 'modir-chat-msg modir-chat-msg-' + role;
        div.textContent = text;
        msgsBox.appendChild(div);
        msgsBox.scrollTop = msgsBox.scrollHeight;

        if (persist !== false) {
            var list = loadStoredMessages();
            list.push({ role: role, text: text });
            saveStoredMessages(list);
        }
    }

    function showTyping() {
        var div = document.createElement('div');
        div.className = 'modir-chat-typing';
        div.id = 'modir-chat-typing-indicator';
        div.innerHTML = '<span></span><span></span><span></span>';
        msgsBox.appendChild(div);
        msgsBox.scrollTop = msgsBox.scrollHeight;
    }

    function hideTyping() {
        var el = document.getElementById('modir-chat-typing-indicator');
        if (el) {
            el.remove();
        }
    }

    function initConversation() {
        var stored = loadStoredMessages();
        if (stored.length) {
            stored.forEach(function (m) {
                appendMessage(m.role, m.text, false);
            });
        } else {
            appendMessage('assistant', ModirChat.welcome, true);
        }
    }

    function resetConversation() {
        try {
            window.localStorage.removeItem(STORAGE_ID_KEY);
            window.localStorage.removeItem(STORAGE_MSGS_KEY);
        } catch (e) {
            /* ignore */
        }
        conversationId = '';
        msgsBox.innerHTML = '';
        appendMessage('assistant', ModirChat.welcome, true);
    }

    function sendMessage(text) {
        if (isSending) {
            return;
        }
        isSending = true;
        appendMessage('user', text, true);
        showTyping();

        var data = new URLSearchParams();
        data.append('action', 'modir_chat_send');
        data.append('nonce', ModirChat.nonce);
        data.append('message', text);
        data.append('conversation_id', getConversationId());

        fetch(ModirChat.ajaxUrl, {
            method: 'POST',
            body: data,
            credentials: 'same-origin'
        }).then(function (r) {
            return r.json();
        }).then(function (res) {
            hideTyping();
            isSending = false;

            if (res && res.success) {
                if (res.data.conversation_id) {
                    setConversationId(res.data.conversation_id);
                }
                appendMessage('assistant', res.data.reply, true);
            } else {
                appendMessage('error', (res && res.data && res.data.message) || ModirChat.i18n.error, false);
            }
        }).catch(function () {
            hideTyping();
            isSending = false;
            appendMessage('error', ModirChat.i18n.error, false);
        });
    }

    bubble.addEventListener('click', function () {
        isOpen = !isOpen;
        panel.style.display = isOpen ? 'flex' : 'none';
        if (isOpen && !msgsBox.childElementCount) {
            initConversation();
        }
        if (isOpen) {
            input.focus();
        }
    });

    if (closeBtn) {
        closeBtn.addEventListener('click', function () {
            isOpen = false;
            panel.style.display = 'none';
        });
    }

    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            if (window.confirm(ModirChat.i18n.clear + '?')) {
                resetConversation();
            }
        });
    }

    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var text = input.value.trim();
            if (!text) {
                return;
            }
            input.value = '';
            input.style.height = 'auto';
            sendMessage(text);
        });
    }

    if (input) {
        input.addEventListener('keydown', function (e) {
            if ('Enter' === e.key && !e.shiftKey) {
                e.preventDefault();
                form.dispatchEvent(new Event('submit', { cancelable: true }));
            }
        });
        input.addEventListener('input', function () {
            input.style.height = 'auto';
            input.style.height = Math.min(input.scrollHeight, 90) + 'px';
        });
    }

    // اگر از لینک اختصاصی (مثلاً پیامک) با پارامتر modir_chat_open=1 وارد شده
    // باشد، دستیار خودکار باز می‌شود.
    try {
        var urlParams = new URLSearchParams(window.location.search);
        if ('1' === urlParams.get('modir_chat_open')) {
            bubble.click();
        }
    } catch (e) {
        // مرورگرهای قدیمی که URLSearchParams ندارند؛ نادیده گرفته می‌شود
    }
})();
