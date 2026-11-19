import { Copy, Download, MessageCircle, Share2 } from 'lucide-react';
import { useRef, useState } from 'react';
import { NewTabHint } from '@/components/new-tab-hint';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useTranslation } from '@/hooks/use-translation';
import { whatsappShareLink } from '@/lib/whatsapp';
import { png as qrPng, svg as qrSvg } from '@/routes/provider/qr';

type Props = {
    // The whole address of the public page, built by the server on APP_URL (public_url).
    url: string;
    published: boolean;
};

// "Il tuo link" in the dashboard (docs/production.md): the address of the public page to put in
// messages and on social media. Unpublished, only the address and a warning: the page gives
// "Pagina non trovata" to everyone, so no button invites to send it yet.
export function ProviderLinkCard({ url, published }: Props) {
    const { t } = useTranslation();
    const field = useRef<HTMLTextAreaElement>(null);
    // Read by screen readers after Copy or a failed Share. Always there, empty at first.
    const [status, setStatus] = useState('');
    // The Web Share API exists on phones and on some computers only: without it, no button.
    const canShare = typeof navigator !== 'undefined' && 'share' in navigator;

    async function copy() {
        try {
            await navigator.clipboard.writeText(url);
            setStatus(t('provider.link.copied'));
        } catch {
            // No clipboard (plain http, an old browser, a refusal): the address is selected, the
            // provider copies it by hand.
            field.current?.focus();
            field.current?.select();
            setStatus(t('provider.link.copy_fallback'));
        }
    }

    async function share() {
        try {
            await navigator.share({ url });
        } catch (error) {
            // The provider closed the share sheet: nothing to say.
            if (error instanceof DOMException && error.name === 'AbortError') {
                return;
            }

            setStatus(t('provider.link.share_failed'));
        }
    }

    return (
        <Card className="max-w-2xl">
            <CardHeader>
                <h2 className="text-xl leading-none font-semibold">
                    {t('provider.link.title')}
                </h2>
                <CardDescription>
                    {t('provider.link.description')}
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
                <div className="grid gap-2">
                    <Label htmlFor="provider-link">
                        {t('provider.link.label')}
                    </Label>
                    {/* Several lines, read only: the whole address stays visible and selectable, and
                        a long one wraps inside a 320 px screen. */}
                    <Textarea
                        ref={field}
                        id="provider-link"
                        readOnly
                        rows={2}
                        value={url}
                        aria-describedby={
                            published ? undefined : 'provider-link-unpublished'
                        }
                        className="min-h-0 resize-none break-all"
                    />
                </div>

                {published ? (
                    <>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={copy}
                            >
                                <Copy aria-hidden="true" />
                                {t('provider.link.copy')}
                            </Button>
                            {canShare && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={share}
                                >
                                    <Share2 aria-hidden="true" />
                                    {t('provider.link.share')}
                                </Button>
                            )}
                            <Button variant="outline" asChild>
                                <a
                                    href={whatsappShareLink(
                                        t('provider.link.whatsapp_text', {
                                            link: url,
                                        }),
                                    )}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <MessageCircle aria-hidden="true" />
                                    {t('provider.link.whatsapp')}
                                    <NewTabHint />
                                </a>
                            </Button>
                        </div>
                        <p role="status" className="text-sm">
                            {status}
                        </p>
                        <QrCode />
                    </>
                ) : (
                    <p
                        id="provider-link-unpublished"
                        className="text-sm text-muted-foreground"
                    >
                        {t('provider.link.unpublished')}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

// The QR code of the public page (published only), made by our server from the same address on
// APP_URL (App\Support\ProviderQrCode): shown as an image, with its own text, and downloadable as
// SVG (for printing, any size) or PNG (1024 px, for social media). The routes need no id: always
// the provider of the session.
function QrCode() {
    const { t } = useTranslation();

    return (
        <section
            aria-labelledby="provider-qr-title"
            className="flex flex-col gap-3 border-t pt-4"
        >
            <h3 id="provider-qr-title" className="font-semibold">
                {t('provider.link.qr_title')}
            </h3>
            {/* Black on white with its own white margin: at least 160 px, never wider than the
                card on a 320 px screen. */}
            <img
                src={qrSvg().url}
                alt={t('provider.link.qr_alt')}
                width={192}
                height={192}
                className="h-auto w-48 max-w-full min-w-40 rounded-md border bg-white"
            />
            <div className="flex flex-wrap gap-2">
                <Button variant="outline" asChild>
                    <a href={qrSvg().url} download>
                        <Download aria-hidden="true" />
                        {t('provider.link.qr_svg')}
                    </a>
                </Button>
                <Button variant="outline" asChild>
                    <a href={qrPng().url} download>
                        <Download aria-hidden="true" />
                        {t('provider.link.qr_png')}
                    </a>
                </Button>
            </div>
            <p className="text-sm text-muted-foreground">
                {t('provider.link.qr_warning')}
            </p>
        </section>
    );
}
