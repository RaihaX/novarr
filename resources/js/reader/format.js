// Pure formatting helpers shared by the reader modules (and unit-tested).

/**
 * "Ch. 12 · Chapter 12 – Title" repeats the number: only prefix it when the
 * label doesn't already lead with it (mirrors $navLabel in chapters/show).
 */
export function chLabel(c, max = 40) {
    const label = c.label || 'Chapter ' + c.chapter;
    const num = String(isNaN(parseFloat(c.chapter)) ? c.chapter : parseFloat(c.chapter));
    const esc = num.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const leads = new RegExp('^\\s*(?:ch(?:apter)?\\.?\\s*)?#?' + esc + '(?![\\d.])', 'i').test(label);
    const text = leads ? label : 'Ch. ' + num + ' · ' + label;
    return text.length > max ? text.substring(0, max - 1) + '…' : text;
}
