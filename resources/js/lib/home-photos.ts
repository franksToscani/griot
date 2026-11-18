// The photos of the categories on the home page (issue #144, docs/photos.md): in the carousel and
// in the row "Categorie". The WebP sizes made by scripts/home-photos.php, imported with Vite (their
// names get a hash, so a cache never keeps an old one). width and height are those of the largest
// size. A category that is not here has no slide, and the empty tile in the row.
// The keys are the slugs of the categories; the files keep the name of their photo (docs/photos.md):
// "Taglio uomo" (taglio-uomo) uses the photo "barbiere", "Unghie" (unghie) the photo "nails", the
// names of the categories before the migration rename_and_merge_categories. The photos added
// later have the name of their category. "Treccine", "Locs" and "Make-up" have no 1920 size: once
// cut to 3:2 they are narrower than 1920 px, and a size is never enlarged (scripts/home-photos.php).
import barbiere1280 from '../../images/home/barbiere-1280.webp';
import barbiere1920 from '../../images/home/barbiere-1920.webp';
import barbiere640 from '../../images/home/barbiere-640.webp';
import nails1280 from '../../images/home/nails-1280.webp';
import nails1920 from '../../images/home/nails-1920.webp';
import nails640 from '../../images/home/nails-640.webp';
import treccine1280 from '../../images/home/treccine-1280.webp';
import treccine640 from '../../images/home/treccine-640.webp';
import taglioDonna640 from '../../images/home/taglio-donna-640.webp';
import taglioDonna1280 from '../../images/home/taglio-donna-1280.webp';
import taglioDonna1920 from '../../images/home/taglio-donna-1920.webp';
import locs640 from '../../images/home/locs-640.webp';
import locs1280 from '../../images/home/locs-1280.webp';
import makeUp640 from '../../images/home/make-up-640.webp';
import makeUp1280 from '../../images/home/make-up-1280.webp';
import cigliaSopracciglia640 from '../../images/home/ciglia-sopracciglia-640.webp';
import cigliaSopracciglia1280 from '../../images/home/ciglia-sopracciglia-1280.webp';
import cigliaSopracciglia1920 from '../../images/home/ciglia-sopracciglia-1920.webp';
import modaAfro640 from '../../images/home/moda-afro-640.webp';
import modaAfro1280 from '../../images/home/moda-afro-1280.webp';
import modaAfro1920 from '../../images/home/moda-afro-1920.webp';
import food640 from '../../images/home/food-640.webp';
import food1280 from '../../images/home/food-1280.webp';
import food1920 from '../../images/home/food-1920.webp';
import type { HomePhoto } from '@/lib/home-carousel';

export const HOME_PHOTOS: Record<string, HomePhoto> = {
    'taglio-uomo': {
        width: 1920,
        height: 1280,
        variants: [
            { width: 640, url: barbiere640 },
            { width: 1280, url: barbiere1280 },
            { width: 1920, url: barbiere1920 },
        ],
    },
    unghie: {
        width: 1920,
        height: 1280,
        variants: [
            { width: 640, url: nails640 },
            { width: 1280, url: nails1280 },
            { width: 1920, url: nails1920 },
        ],
    },
    treccine: {
        width: 1280,
        height: 853,
        variants: [
            { width: 640, url: treccine640 },
            { width: 1280, url: treccine1280 },
        ],
    },
    'taglio-donna': {
        width: 1920,
        height: 1280,
        variants: [
            { width: 640, url: taglioDonna640 },
            { width: 1280, url: taglioDonna1280 },
            { width: 1920, url: taglioDonna1920 },
        ],
    },
    locs: {
        width: 1280,
        height: 853,
        variants: [
            { width: 640, url: locs640 },
            { width: 1280, url: locs1280 },
        ],
    },
    'make-up': {
        width: 1280,
        height: 853,
        variants: [
            { width: 640, url: makeUp640 },
            { width: 1280, url: makeUp1280 },
        ],
    },
    'ciglia-sopracciglia': {
        width: 1920,
        height: 1280,
        variants: [
            { width: 640, url: cigliaSopracciglia640 },
            { width: 1280, url: cigliaSopracciglia1280 },
            { width: 1920, url: cigliaSopracciglia1920 },
        ],
    },
    'moda-afro': {
        width: 1920,
        height: 1280,
        variants: [
            { width: 640, url: modaAfro640 },
            { width: 1280, url: modaAfro1280 },
            { width: 1920, url: modaAfro1920 },
        ],
    },
    food: {
        width: 1920,
        height: 1280,
        variants: [
            { width: 640, url: food640 },
            { width: 1280, url: food1280 },
            { width: 1920, url: food1920 },
        ],
    },
};
