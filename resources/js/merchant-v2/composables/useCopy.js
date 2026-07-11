import { ref } from 'vue';
import { copyTextToClipboard } from '../../utils/clipboard';

export function useCopy() {
    const copied = ref('');
    const copyError = ref('');

    const copy = async (value, label = 'Copied') => {
        const result = await copyTextToClipboard(value);

        if (result.ok) {
            copied.value = label;
            copyError.value = '';
            window.setTimeout(() => {
                if (copied.value === label) copied.value = '';
            }, 1400);
            return true;
        }

        copied.value = '';
        copyError.value = result.message || 'Failed to copy.';
        return false;
    };

    return { copied, copyError, copy };
}
