import Picker from 'emoji-picker-element/picker.js';
import ruI18n from 'emoji-picker-element/i18n/ru_RU.js';
import emojiDataUrl from 'emoji-picker-element-data/ru/emojibase/data.json?url';

type EditorNode = {
    isText?: boolean;
    marks?: unknown[];
};

type EditorSelection = {
    from: number;
    to: number;
    empty: boolean;
};

type EditorLike = {
    state: {
        selection: EditorSelection;
        doc: {
            resolve: (position: number) => {
                nodeBefore: EditorNode | null;
                nodeAfter: EditorNode | null;
            };
        };
    };
    chain: () => EditorChain;
    commands: {
        focus: () => boolean;
    };
};

type EditorChain = {
    focus: () => EditorChain;
    setTextSelection: (selection: { from: number; to: number }) => EditorChain;
    unsetAllMarks: () => EditorChain;
    insertContent: (content: string) => EditorChain;
    run: () => boolean;
};

type EmojiDetail = {
    unicode?: unknown;
    emoji?: {
        unicode?: unknown;
    };
};

type ActivePicker = {
    picker: Picker;
    editor: EditorLike;
    selection: EditorSelection;
    button: HTMLElement;
};

let activePicker: ActivePicker | null = null;

function hasMarks(node: EditorNode | null): boolean {
    return node?.isText === true && (node.marks?.length ?? 0) > 0;
}

function isFormattingBoundary(editor: EditorLike, selection: EditorSelection): boolean {
    if (!selection.empty) {
        return false;
    }

    const resolved = editor.state.doc.resolve(selection.from);

    return hasMarks(resolved.nodeBefore)
        && !hasMarks(resolved.nodeAfter);
}

function closePicker(restoreFocus: boolean): void {
    if (activePicker === null) {
        return;
    }

    const { editor, picker } = activePicker;
    picker.remove();
    window.removeEventListener('resize', repositionPicker);
    document.removeEventListener('pointerdown', handleOutsidePointer, true);
    document.removeEventListener('keydown', handleEscape);
    activePicker = null;

    if (restoreFocus) {
        editor.commands.focus();
    }
}

function repositionPicker(): void {
    if (activePicker === null) {
        return;
    }

    const { button, picker } = activePicker;
    const gap = 8;
    const edge = 8;
    const width = Math.min(360, Math.max(280, window.innerWidth - edge * 2));
    const height = Math.min(480, Math.max(320, window.innerHeight - edge * 2));
    const buttonRect = button.getBoundingClientRect();
    const left = Math.min(
        Math.max(edge, buttonRect.right - width),
        Math.max(edge, window.innerWidth - width - edge),
    );
    const belowTop = buttonRect.bottom + gap;
    const top = belowTop + height <= window.innerHeight - edge
        ? belowTop
        : Math.max(edge, buttonRect.top - height - gap);

    picker.style.setProperty('position', 'fixed');
    picker.style.setProperty('z-index', '1000');
    picker.style.setProperty('width', String(width) + 'px');
    picker.style.setProperty('height', String(height) + 'px');
    picker.style.setProperty('max-width', 'calc(100vw - ' + String(edge * 2) + 'px)');
    picker.style.setProperty('max-height', 'calc(100svh - ' + String(edge * 2) + 'px)');
    picker.style.setProperty('left', String(left) + 'px');
    picker.style.setProperty('top', String(top) + 'px');
    picker.style.setProperty('border-radius', '0.75rem');
    picker.style.setProperty('box-shadow', '0 1rem 2.5rem rgb(0 0 0 / 24%)');
}

function handleOutsidePointer(event: PointerEvent): void {
    if (activePicker === null) {
        return;
    }

    const path = event.composedPath();
    if (path.includes(activePicker.picker) || path.includes(activePicker.button)) {
        return;
    }

    closePicker(true);
}

function handleEscape(event: KeyboardEvent): void {
    if (event.key !== 'Escape') {
        return;
    }

    event.preventDefault();
    closePicker(true);
}

function insertEmoji(event: Event): void {
    if (activePicker === null) {
        return;
    }

    const detail = (event as CustomEvent<EmojiDetail>).detail;
    const unicode = typeof detail?.unicode === 'string'
        ? detail.unicode
        : typeof detail?.emoji?.unicode === 'string'
            ? detail.emoji.unicode
            : null;
    const { editor, selection } = activePicker;

    if (unicode === null || unicode === '') {
        closePicker(true);

        return;
    }

    const chain = editor.chain()
        .focus()
        .setTextSelection({ from: selection.from, to: selection.to });
    if (isFormattingBoundary(editor, selection)) {
        chain.unsetAllMarks();
    }
    chain.insertContent(unicode).run();
    closePicker(true);
}

function toggleEmojiPicker(event: MouseEvent, editor: EditorLike | null | undefined): void {
    event.preventDefault();

    if (editor === null || editor === undefined || !(event.currentTarget instanceof HTMLElement)) {
        return;
    }

    if (activePicker !== null) {
        const sameEditor = activePicker.editor === editor;
        closePicker(true);
        if (sameEditor) {
            return;
        }
    }

    const selection = editor.state.selection;
    const picker = new Picker({
        dataSource: emojiDataUrl,
        locale: 'ru',
        i18n: ruI18n,
    });
    const button = event.currentTarget;
    activePicker = {
        picker,
        editor,
        selection: {
            from: selection.from,
            to: selection.to,
            empty: selection.empty,
        },
        button,
    };
    picker.addEventListener('emoji-click', insertEmoji);
    document.body.append(picker);
    repositionPicker();
    window.addEventListener('resize', repositionPicker);
    document.addEventListener('pointerdown', handleOutsidePointer, true);
    document.addEventListener('keydown', handleEscape);
}

function handleKeydown(event: KeyboardEvent, editor: EditorLike | null | undefined): void {
    if (editor === null || editor === undefined || event.key !== ' ' || event.defaultPrevented) {
        return;
    }

    if (isFormattingBoundary(editor, editor.state.selection)) {
        editor.chain().focus().unsetAllMarks().run();
    }
}

declare global {
    interface Window {
        ChuklovRichTextEditor: {
            toggleEmojiPicker: typeof toggleEmojiPicker;
            handleKeydown: typeof handleKeydown;
        };
    }
}

window.ChuklovRichTextEditor = {
    toggleEmojiPicker,
    handleKeydown,
};
