// The photos of the home carousel (issue #144, docs/photos.md): the WebP sizes made by
// scripts/home-photos.php, imported with Vite (their names get a hash, so a cache never keeps an
// old one). width and height are those of the largest size. A category that is not here has no
// photo, and its slide stays a coloured tile.
import barbiere1280 from '../../images/home/barbiere-1280.webp';
import barbiere1920 from '../../images/home/barbiere-1920.webp';
import barbiere640 from '../../images/home/barbiere-640.webp';
import nails1280 from '../../images/home/nails-1280.webp';
import nails1920 from '../../images/home/nails-1920.webp';
import nails640 from '../../images/home/nails-640.webp';
import treccine1280 from '../../images/home/treccine-1280.webp';
import treccine1920 from '../../images/home/treccine-1920.webp';
import treccine640 from '../../images/home/treccine-640.webp';
import type { HomePhoto } from '@/lib/home-carousel';

export const HOME_PHOTOS: Record<string, HomePhoto> = {
    barbiere: {
        width: 1920,
        height: 1280,
        variants: [
            { width: 640, url: barbiere640 },
            { width: 1280, url: barbiere1280 },
            { width: 1920, url: barbiere1920 },
        ],
    },
    nails: {
        width: 1920,
        height: 1280,
        variants: [
            { width: 640, url: nails640 },
            { width: 1280, url: nails1280 },
            { width: 1920, url: nails1920 },
        ],
    },
    treccine: {
        width: 1920,
        height: 1280,
        variants: [
            { width: 640, url: treccine640 },
            { width: 1280, url: treccine1280 },
            { width: 1920, url: treccine1920 },
        ],
    },
};
