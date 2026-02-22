# Automatické generování srcset a WEBP konverze

Image Storage automaticky generuje `srcset` atribut pro responsive obrázky a převádí JPG/PNG do WEBP formátu.

## 📝 Syntaxe

```latte
n:img="path, srcset|size, flag, quality, convertToWebp"
```

### Argumenty (v pořadí):

| Pozice | Parametr | Typ | Výchozí | Popis |
|--------|----------|-----|---------|-------|
| **1** | `path` | `string` | - | **Cesta k obrázku** (povinné) |
| **2** | `srcset` nebo `size` | `array` nebo `string` | - | **Pole rozměrů** `['400', '800']` nebo **jeden rozměr** `'800x600'` |
| **3** | `flag` | `string` | `'fit'` | Způsob změny velikosti: `fit`, `fill`, `exact`, `stretch`, `shrink_only` |
| **4** | `quality` | `int` | auto | Kvalita komprese (0-100 pro JPEG/WebP, 0-9 pro PNG) |
| **5** | `convertToWebp` | `bool` | `true` | Automaticky převádí JPG/PNG do WEBP |

---

## 🚀 Základní použití

### Minimální (cesta + rozměry)

```latte
<img n:img="$image->getPath(), ['400', '800', '1200']" alt="Responsive obrázek">
```

**Vygeneruje:**
```html
<img src="data/path/image.1200x537.webp"
     srcset="data/path/image.400x179.webp 400w,
             data/path/image.800x358.webp 800w,
             data/path/image.1200x537.webp 1200w"
     alt="Responsive obrázek">
```

### S flagem

```latte
<img n:img="$product->getImage(), ['400', '800'], 'fill'" alt="Produkt">
```

### S kvalitou

```latte
<img n:img="$product->getImage(), ['400', '800', '1200'], 'fill', 90" alt="Produkt">
```

### Kompletní (všechny parametry)

```latte
<img n:img="$image->getPath(), ['400', '800', '1200'], 'fill', 85, true"
     alt="Obrázek">
```

### Jeden rozměr (bez srcset)

```latte
<img n:img="$image->getPath(), '800x600', 'fit'" alt="Obrázek">
```

---

## 🎯 Zkrácený zápis rozměrů

**Zadejte pouze šířku** - výška se dopočítá podle poměru stran originálního obrázku:

```latte
<img n:img="$image->getPath(), ['1200', '800', '400']" alt="Obrázek">
```

### Jak funguje automatický výpočet

Pro originální obrázek **1200x537 px** (poměr stran 2.234):

| Zadání | Výpočet | Výsledek |
|--------|---------|----------|
| `'1200'` | 1200 ÷ 2.234 | `1200x537` |
| `'800'` | 800 ÷ 2.234 | `800x358` |
| `'400'` | 400 ÷ 2.234 | `400x179` |

### Kombinace formátů

Můžete kombinovat plný a zkrácený formát:

```latte
<img n:img="$image->getPath(), ['1200x537', '800', '400']" alt="Obrázek">
```

---

## 🌐 Automatická konverze do WEBP

**Výchozí chování:** Všechny JPG a PNG obrázky se **automaticky převádí do WEBP** formátu.

### Zapnutá konverze (výchozí)

```latte
<img n:img="$image->getPath(), ['400', '800']" alt="Obrázek">
```

**Vygeneruje WEBP:**
```html
<img src="data/path/image.800x358.webp"
     srcset="data/path/image.400x179.webp 400w, data/path/image.800x358.webp 800w"
     alt="Obrázek">
```

### Vypnutá konverze

```latte
<img n:img="$image->getPath(), ['400', '800'], 'fit', 85, false" alt="Obrázek">
```

**Zachová JPG/PNG:**
```html
<img src="data/path/image.800x358.jpg"
     srcset="data/path/image.400x179.jpg 400w, data/path/image.800x358.jpg 800w"
     alt="Obrázek">
```

### Výhody WEBP

- ✅ **30-50% menší soubory** než JPG/PNG
- ✅ Rychlejší načítání stránky
- ✅ Úspora bandwidth
- ✅ Lepší SEO (Core Web Vitals)
- ✅ Podpora transparentnosti (jako PNG)
- ✅ Podporováno všemi moderními prohlížeči

---

## 💡 Běžné vzory použití

### Hero obrázek (fullwidth)

```latte
<img n:img="$hero->getImage(), ['800', '1600', '2400'], 'fill'"
     alt="{$hero->title}"
     class="hero-image">
```

### Produktový obrázek

```latte
<img n:img="$product->getImage(), ['300', '600', '900'], 'fit', 90"
     alt="{$product->name}"
     class="product-image">
```

### Thumbnail v galerii

```latte
<img n:img="$photo->getPath(), ['200', '400'], 'fill'"
     alt="{$photo->title}"
     loading="lazy">
```

### Blog článek

```latte
<img n:img="$article->getFeaturedImage(), ['600', '1200'], 'fit'"
     alt="{$article->title}"
     class="article-image">
```

---

## 🔗 Použití s imgLink tagem

### Generování URL (bez HTML tagu)

```latte
{* Pouze URL s rozměrem *}
{var $imageUrl = {imgLink $image->getPath(), '800x600'}}

{* URL s flagem *}
<a href="{imgLink $image->getPath(), '1920x1080', 'fit'}">Stáhnout HD</a>

{* URL s kompletními parametry *}
<div style="background-image: url({imgLink $image->getPath(), '1920x1080', 'fill', 90, true})"></div>
```

---

## ⚙️ Resize flagy

| Flag | Chování | Použití |
|------|---------|---------|
| `fit` | Přizpůsobí do rozměrů (zachová poměr stran) | **Výchozí**, univerzální |
| `fill` | Vyplní celý prostor (může ořezat) | Thumbnaily, preview |
| `exact` | Přesné rozměry (deformuje) | Specifické případy |
| `stretch` | Roztáhne obrázek | Raritní použití |
| `shrink_only` | Pouze zmenšuje (nikdy nezvětšuje) | Zachování kvality |

### Příklady

```latte
{* Fit - zachová poměr stran *}
<img n:img="$image->getPath(), '800x600', 'fit'" alt="Obrázek">

{* Fill - vyplní prostor, může ořezat *}
<img n:img="$image->getPath(), '800x600', 'fill'" alt="Obrázek">
```

---

## 📋 Poznámky

- **Zkrácený zápis:** Zadejte pouze šířku (např. `'800'`) a výška se automaticky dopočítá podle poměru stran originálního obrázku
- **Rozměry v srcset:** Seřaďte od nejmenšího k největšímu
- **Hlavní src:** Použije se největší rozměr (poslední v poli `srcset`)
- **Width descriptor:** Automaticky se extrahuje z rozměru (např. `'400x300'` → `400w`)
- **Stejné parametry:** Všechny obrázky v srcset používají stejný `flag` a `quality`
- **WEBP konverze:** Výchozí chování, můžete vypnout pomocí `false` jako 5. parametr
- **GIF a SVG:** Nepřevádějí se do WEBP (zachovávají originální formát)
- **CSS sizes atribut:** Pokud potřebujete, přidejte ručně v HTML: `sizes="(min-width: 992px) 50vw, 100vw"`

---

## 🎯 Rychlá reference

```latte
{* Minimální - srcset s WEBP *}
<img n:img="$image->getPath(), ['400', '800']">

{* S flagem *}
<img n:img="$image->getPath(), ['400', '800'], 'fill'">

{* S kvalitou *}
<img n:img="$image->getPath(), ['400', '800'], 'fill', 90">

{* Bez WEBP konverze *}
<img n:img="$image->getPath(), ['400', '800'], 'fill', 85, false">

{* Kompletní (všechny parametry) *}
<img n:img="$image->getPath(), ['400', '800', '1200'], 'fill', 85, true">

{* Jeden rozměr (bez srcset) *}
<img n:img="$image->getPath(), '800x600'">

{* Link (URL) *}
{imgLink $image->getPath(), '800x600', 'fit'}

{* Pokud potřebujete sizes atribut, přidejte ručně *}
<img n:img="$image->getPath(), ['400', '800']"
     sizes="(min-width: 992px) 50vw, 100vw">
```
