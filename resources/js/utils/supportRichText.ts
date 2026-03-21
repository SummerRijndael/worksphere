import MarkdownIt from 'markdown-it';
import { sanitizeHtml } from '@/utils/sanitize';

const renderer = new MarkdownIt({
    html: true,
    linkify: true,
    breaks: true,
});

const defaultLinkOpen = renderer.renderer.rules.link_open || ((tokens, idx, options, _env, self) => {
    return self.renderToken(tokens, idx, options);
});

renderer.renderer.rules.link_open = (tokens, idx, options, env, self) => {
    const token = tokens[idx];
    token.attrSet('target', '_blank');
    token.attrSet('rel', 'noopener noreferrer nofollow');
    return defaultLinkOpen(tokens, idx, options, env, self);
};

const SUPPORT_MESSAGE_SANITIZE_CONFIG = {
    ALLOWED_TAGS: [
        'p',
        'br',
        'strong',
        'em',
        'u',
        's',
        'del',
        'ins',
        'a',
        'ul',
        'ol',
        'li',
        'blockquote',
        'code',
        'pre',
    ],
    ALLOWED_ATTR: [
        'href',
        'target',
        'rel',
    ],
    ALLOW_DATA_ATTR: false,
};

export function renderSupportRichText(value: unknown): string {
    const content = String(value ?? '').trim();
    if (content === '') {
        return '';
    }

    const html = renderer.render(content);
    return sanitizeHtml(html, SUPPORT_MESSAGE_SANITIZE_CONFIG);
}

