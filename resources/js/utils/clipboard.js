const legacyCopy = (text) => {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', 'true');
    textarea.style.position = 'fixed';
    textarea.style.top = '0';
    textarea.style.left = '0';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.focus();
    textarea.select();

    let copied = false;

    try {
        copied = document.execCommand('copy');
    } finally {
        document.body.removeChild(textarea);
    }

    return copied;
};

export const copyTextToClipboard = async (value) => {
    if (value === null || value === undefined) {
        return { ok: false, message: 'Nothing to copy.' };
    }

    const text = String(value);

    if (text.trim() === '') {
        return { ok: false, message: 'Nothing to copy.' };
    }

    try {
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            await navigator.clipboard.writeText(text);
            return { ok: true };
        }

        if (legacyCopy(text)) {
            return { ok: true };
        }

        return { ok: false, message: 'Clipboard access is not available.' };
    } catch {
        try {
            if (legacyCopy(text)) {
                return { ok: true };
            }
        } catch {
            // no-op
        }

        return { ok: false, message: 'Failed to copy to clipboard.' };
    }
};
