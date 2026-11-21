// A photo made smaller in the browser before it is sent (issue #143): a phone photo is often
// 3-6 MB and 4000 pixels or more, the server takes at most 4 MB for the gallery, 1.5 MB for the
// provider's photo, and 4000 pixels a side. Smaller also means faster on a mobile connection. The
// server checks everything again.

// The longest side of the photo sent, in pixels, and the JPEG quality (0-1).
export const MAX_SIDE = 2400;
export const JPEG_QUALITY = 0.85;

// The provider's photo (#53): the server keeps it at 512 px on the longest side
// (StoreProviderAvatar), so 1024 leaves it twice the pixels it needs, and a JPEG of 1024 px at
// 0.85 stays far below its 1.5 MB (a few hundred KB for a photo).
export const AVATAR_MAX_SIDE = 1024;

// Whether a photo must be made smaller before it is sent: only when it is heavier than what the
// server takes (in KB, as Laravel's "max" rule counts them). A lighter one goes as it is, not
// rewritten: a PNG with transparency stays a PNG, a panorama keeps its pixels.
export function needsResize(
    file: { size: number },
    maxKilobytes: number,
): boolean {
    return file.size > maxKilobytes * 1024;
}

export type Size = { width: number; height: number };

// The size to send: the longest side at most maxSide, the proportions kept, never enlarged.
export function targetSize(
    width: number,
    height: number,
    maxSide: number = MAX_SIDE,
): Size {
    const scale = Math.min(1, maxSide / Math.max(width, height));

    return {
        width: Math.max(1, Math.round(width * scale)),
        height: Math.max(1, Math.round(height * scale)),
    };
}

// The photo as a JPEG at most maxSide on its longest side, turned upright as the phone took it.
// If the browser cannot read or write it, the original goes as it is: the server decides.
export async function resizeImage(
    file: File,
    maxSide: number = MAX_SIDE,
    quality: number = JPEG_QUALITY,
): Promise<File> {
    try {
        const bitmap = await createImageBitmap(file, {
            imageOrientation: 'from-image',
        });
        const size = targetSize(bitmap.width, bitmap.height, maxSide);
        const canvas = document.createElement('canvas');
        canvas.width = size.width;
        canvas.height = size.height;
        const context = canvas.getContext('2d');

        if (context === null) {
            bitmap.close();

            return file;
        }

        context.drawImage(bitmap, 0, 0, size.width, size.height);
        bitmap.close();

        const blob = await new Promise<Blob | null>((resolve) =>
            canvas.toBlob(resolve, 'image/jpeg', quality),
        );

        if (blob === null) {
            return file;
        }

        const name = file.name.replace(/\.[^.]*$/, '') || 'foto';

        return new File([blob], `${name}.jpg`, { type: 'image/jpeg' });
    } catch {
        return file;
    }
}
