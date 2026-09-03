<script lang="ts">
import { defineComponent, h, type VNodeChild } from 'vue';

type InlineNode =
    | { kind: 'text'; value: string }
    | { kind: 'break' }
    | { kind: 'code'; value: string }
    | { kind: 'strong'; children: InlineNode[] }
    | { kind: 'emphasis'; children: InlineNode[] }
    | { kind: 'link'; href: string; children: InlineNode[] };

type BlockNode =
    | { kind: 'paragraph'; lines: string[] }
    | { kind: 'heading'; level: 1 | 2 | 3; value: string }
    | { kind: 'unordered-list'; items: string[] }
    | { kind: 'ordered-list'; items: string[] }
    | { kind: 'code-block'; value: string };

const MAX_RENDERED_LENGTH = 20000;

function safeHref(value: string): string | null {
    if (value.length > 2048) {
        return null;
    }

    try {
        const url = new URL(value);

        if (url.protocol !== 'https:' || url.hostname === '' || url.username !== '' || url.password !== '') {
            return null;
        }

        return url.toString();
    } catch {
        return null;
    }
}

function inlineNodes(value: string): InlineNode[] {
    const nodes: InlineNode[] = [];
    let plain = '';
    let index = 0;

    const flushPlain = (): void => {
        if (plain !== '') {
            nodes.push({ kind: 'text', value: plain });
            plain = '';
        }
    };

    while (index < value.length) {
        if (value[index] === '`') {
            const end = value.indexOf('`', index + 1);
            if (end > index + 1) {
                flushPlain();
                nodes.push({ kind: 'code', value: value.slice(index + 1, end) });
                index = end + 1;
                continue;
            }
        }

        if (value[index] === '[') {
            const link = value.slice(index).match(/^\[([^\]\n]{1,200})\]\(([^\s)<>]{1,2048})\)/);
            if (link !== null) {
                flushPlain();
                const href = safeHref(link[2]);
                if (href === null) {
                    nodes.push({ kind: 'text', value: link[1] });
                } else {
                    nodes.push({ kind: 'link', href, children: inlineNodes(link[1]) });
                }
                index += link[0].length;
                continue;
            }
        }

        const marker = value.slice(index, index + 2);
        if (marker === '**' || marker === '__') {
            const end = value.indexOf(marker, index + 2);
            if (end > index + 2) {
                flushPlain();
                nodes.push({ kind: 'strong', children: inlineNodes(value.slice(index + 2, end)) });
                index = end + 2;
                continue;
            }
        }

        if (value[index] === '*' || value[index] === '_') {
            const marker = value[index];
            const end = value.indexOf(marker, index + 1);
            if (end > index + 1 && (marker !== '_' || index === 0 || /\s/.test(value[index - 1]))) {
                flushPlain();
                nodes.push({ kind: 'emphasis', children: inlineNodes(value.slice(index + 1, end)) });
                index = end + 1;
                continue;
            }
        }

        if (value[index] === '\n') {
            flushPlain();
            nodes.push({ kind: 'break' });
            index += 1;
            continue;
        }

        plain += value[index];
        index += 1;
    }

    flushPlain();

    return nodes;
}

function blockNodes(value: string): BlockNode[] {
    const lines = value.slice(0, MAX_RENDERED_LENGTH).replaceAll('\r\n', '\n').split('\n');
    const blocks: BlockNode[] = [];
    let index = 0;

    while (index < lines.length) {
        const line = lines[index];
        if (line.trim() === '') {
            index += 1;
            continue;
        }

        if (/^\s*```/.test(line)) {
            const code: string[] = [];
            index += 1;
            while (index < lines.length && !/^\s*```\s*$/.test(lines[index])) {
                code.push(lines[index]);
                index += 1;
            }
            if (index < lines.length) {
                index += 1;
            }
            blocks.push({ kind: 'code-block', value: code.join('\n') });
            continue;
        }

        const heading = line.match(/^\s*(#{1,3})\s+(.+?)\s*#*\s*$/);
        if (heading !== null) {
            blocks.push({ kind: 'heading', level: heading[1].length as 1 | 2 | 3, value: heading[2] });
            index += 1;
            continue;
        }

        const unordered = line.match(/^\s*[-*+]\s+(.+)$/);
        if (unordered !== null) {
            const items: string[] = [];
            while (index < lines.length) {
                const item = lines[index].match(/^\s*[-*+]\s+(.+)$/);
                if (item === null) {
                    break;
                }
                items.push(item[1]);
                index += 1;
            }
            blocks.push({ kind: 'unordered-list', items });
            continue;
        }

        const ordered = line.match(/^\s*\d+[.)]\s+(.+)$/);
        if (ordered !== null) {
            const items: string[] = [];
            while (index < lines.length) {
                const item = lines[index].match(/^\s*\d+[.)]\s+(.+)$/);
                if (item === null) {
                    break;
                }
                items.push(item[1]);
                index += 1;
            }
            blocks.push({ kind: 'ordered-list', items });
            continue;
        }

        const paragraph: string[] = [];
        while (index < lines.length && lines[index].trim() !== '') {
            if (paragraph.length > 0 && (/^\s*```/.test(lines[index])
                || /^\s*(#{1,3})\s+/.test(lines[index])
                || /^\s*[-*+]\s+/.test(lines[index])
                || /^\s*\d+[.)]\s+/.test(lines[index]))) {
                break;
            }
            paragraph.push(lines[index]);
            index += 1;
        }
        blocks.push({ kind: 'paragraph', lines: paragraph });
    }

    return blocks;
}

function renderInline(nodes: InlineNode[], keyPrefix: string): VNodeChild[] {
    return nodes.map((node, index) => {
        const key = `${keyPrefix}-${index}`;

        if (node.kind === 'text') {
            return node.value;
        }
        if (node.kind === 'break') {
            return h('br', { key });
        }
        if (node.kind === 'code') {
            return h('code', { key, class: 'portal-rich-text__inline-code' }, node.value);
        }
        if (node.kind === 'strong') {
            return h('strong', { key }, renderInline(node.children, key));
        }
        if (node.kind === 'emphasis') {
            return h('em', { key }, renderInline(node.children, key));
        }

        return h('a', {
            key,
            href: node.href,
            target: '_blank',
            rel: 'noopener noreferrer',
        }, renderInline(node.children, key));
    });
}

function renderInlineText(value: string, key: string): VNodeChild[] {
    return renderInline(inlineNodes(value), key);
}

function renderBlocks(blocks: BlockNode[]): VNodeChild[] {
    return blocks.map((block, index) => {
        const key = `block-${index}`;

        if (block.kind === 'paragraph') {
            return h('p', { key }, renderInlineText(block.lines.join('\n'), key));
        }
        if (block.kind === 'heading') {
            return h(`h${block.level}`, { key }, renderInlineText(block.value, key));
        }
        if (block.kind === 'code-block') {
            return h('pre', { key, class: 'portal-rich-text__code' }, [h('code', block.value)]);
        }
        if (block.kind === 'unordered-list') {
            return h('ul', { key }, block.items.map((item, itemIndex) => h('li', { key: `${key}-${itemIndex}` }, renderInlineText(item, `${key}-${itemIndex}`))));
        }

        return h('ol', { key }, block.items.map((item, itemIndex) => h('li', { key: `${key}-${itemIndex}` }, renderInlineText(item, `${key}-${itemIndex}`))));
    });
}

export default defineComponent({
    name: 'SafeRichText',
    props: {
        content: {
            type: String,
            required: true,
        },
        contentHtml: {
            type: String,
            default: null,
        },
    },
    setup(props) {
        return () => props.contentHtml !== null
            ? h('div', { class: 'portal-rich-text', innerHTML: props.contentHtml })
            : h('div', { class: 'portal-rich-text' }, renderBlocks(blockNodes(props.content)));
    },
});
</script>
