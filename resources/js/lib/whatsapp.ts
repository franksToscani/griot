// A WhatsApp chat with a text already written: the address of the number (https://wa.me/<digits>,
// built by the server from a valid international number) and the text, encoded so that accents,
// apostrophes, "&", "?" and line breaks stay as they are. The text is the caller's: it must never
// hold the customer's data or a private link.
export function whatsappLink(numberUrl: string, text: string): string {
    return `${numberUrl}?text=${encodeURIComponent(text)}`;
}
