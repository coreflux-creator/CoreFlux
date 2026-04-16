# CoreFlux Brand & Design Guidelines

## Brand Identity

### Brand Essence
CoreFlux represents **clarity**, **stability**, and **adaptability** - a dynamic, modular business platform that centralizes financial, employee, and operational insights.

### Tagline
**"Power Your Core. Evolve with Flux."**

### Brand Voice & Tone
- Professional, yet forward-thinking
- Confident and precise
- Emphasizes clarity and evolution

---

## Color Palette

### Primary Colors
| Color Name | Hex Code | Usage |
|------------|----------|-------|
| **Core Navy** | `#0A2540` | Primary brand color, headers, buttons |
| **Flux Blue** | `#007FFF` | Accent color, links, highlights |

### Secondary Colors
| Color Name | Hex Code | Usage |
|------------|----------|-------|
| **Soft Gray** | `#F5F7FA` | Backgrounds, cards |
| **Dark Gray** | `#3A3F45` | Body text |

### Hover States
| Base Color | Hover Color |
|------------|-------------|
| Core Navy `#0A2540` | `#061829` |
| Flux Blue `#007FFF` | `#0066CC` |

### Background Options
- **Preferred:** White or very light gray (`#F5F7FA`)
- **Alternate (Dark):** `#0A2540` with white logo variant

---

## Typography

### Primary Font
**Montserrat** (fallback: Inter, system-ui, sans-serif)

### Font Hierarchy
| Element | Size | Weight | Color |
|---------|------|--------|-------|
| H1 (Main Heading) | 32px | Bold (700) | Core Navy `#0A2540` |
| H2 (Subheading) | 24px | SemiBold (600) | Core Navy `#0A2540` |
| Body Text | 16px | Regular (400) | Dark Gray `#3A3F45` |
| Small/Accent | 14px | Regular/Medium | Dark Gray `#3A3F45` |

### Font Loading
```html
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
```

---

## Logo Usage

### Logo Components
1. **Swirl Emblem** - Positioned to the left
2. **"Core"** - Bold navy text
3. **"Flux"** - Vibrant blue text
4. **Tagline** (optional) - Below the logo

### Logo Spacing
Maintain clear space equal to the height of the "C" in "Core" around all sides of the logo.

### Logo Variants
- **Default:** Navy swirl with navy/blue text on light background
- **White:** White swirl and text on dark background (`#0A2540`)

### Logo Don'ts
- Don't distort, rotate, or stretch the logo
- Don't change the color palette
- Don't place on complex or low-contrast backgrounds

---

## Iconography

### Style Guidelines
- Rounded, minimal icons
- Consistent line width and stroke weight
- Lucide React library for React applications

### Icon Colors
- Primary: Core Navy (`#0A2540`)
- Accent: Flux Blue (`#007FFF`)
- Muted: Dark Gray at 60% opacity

---

## UI Components

### Buttons
```css
/* Primary Button */
.btn-primary {
  background-color: #0A2540; /* cf-navy */
  color: white;
  padding: 0.625rem 1rem;
  border-radius: 0.5rem;
  font-weight: 600;
  transition: background-color 0.2s;
}
.btn-primary:hover {
  background-color: #061829; /* cf-navy-dark */
}

/* Accent Button */
.btn-accent {
  background-color: #007FFF; /* cf-flux */
  color: white;
}
.btn-accent:hover {
  background-color: #0066CC;
}
```

### Form Inputs
```css
input, select, textarea {
  border: 1px solid #E5E7EB;
  border-radius: 0.5rem;
  padding: 0.625rem 1rem;
  color: #3A3F45;
}
input:focus, select:focus, textarea:focus {
  border-color: #007FFF;
  box-shadow: 0 0 0 2px rgba(0, 127, 255, 0.25);
}
```

### Cards
```css
.card {
  background: white;
  border: 1px solid #E5E7EB;
  border-radius: 0.75rem;
  padding: 1.5rem;
}
.card:hover {
  box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
  border-color: rgba(0, 127, 255, 0.3);
}
```

---

## Tailwind CSS Configuration

```javascript
// tailwind.config.js
module.exports = {
  theme: {
    extend: {
      colors: {
        'cf-navy': '#0A2540',
        'cf-navy-dark': '#061829',
        'cf-flux': '#007FFF',
        'cf-flux-hover': '#0066CC',
        'cf-soft': '#F5F7FA',
        'cf-dark': '#3A3F45',
      },
      fontFamily: {
        'sans': ['Montserrat', 'Inter', 'system-ui', 'sans-serif'],
      },
    },
  },
}
```

---

## Application Examples

### Web UI
- Use consistent padding and spacing
- Integrate the swirl icon subtly in headers, footers, or backgrounds
- Maintain visual hierarchy with proper font sizes

### Email Signatures
```
Name | Title
CoreFlux
www.corefluxapp.com
email@corefluxapp.com | +1 (XXX) XXX-XXXX
```

---

## Imagery Style
- Tech-forward, clean images
- Natural lighting
- Focus on modularity, flow, and control
- Abstract shapes or wave patterns derived from the spiral emblem

---

## File Assets

| Asset | Location |
|-------|----------|
| Logo (PNG) | `/public/logo.png` |
| Logo (SVG) | `/public/logo.svg` |
| Brand Guide (PDF) | Uploaded brand assets |

---

*Last Updated: March 2026*
*CoreFlux Brand Guidelines v1.0*
