# Figma-to-Drupal Mapping

Source file: `EylqtwZFAU4lq3hfzO26cd`  
Homepage frame: `4201:3`

| Figma node | Section | Drupal implementation |
|---|---|---|
| `4201:424` | Header | `page.html.twig`, top and primary menu regions |
| `4201:52` | Hero | `page--front.html.twig`, `hero` region |
| `4201:4` | Quick Services | `quick_services` region / service-card SDC |
| `4201:72` | Operations & Impact | `impact` region |
| `4201:144` | Service Status | `service_status` region / status-item SDC |
| `4201:210` | News & Insights | `news` region / news-card SDC |
| `4201:257` | Tenders | `tenders` region / tender-item SDC |
| `4201:377` | Featured Videos | `featured_video` region |
| `4201:317` | Partners | `partners` region |
| `4201:335` | Footer | footer regions and footer menu |

## Design tokens

```css
--reg-red: #da291c;
--reg-red-soft: #fff0ee;
--reg-red-panel: #ffe2dd;
--reg-ink: #281715;
--reg-muted: #5d5e61;
--reg-surface: #f8f9fa;
--reg-border: #e0e0e0;
--reg-black: #000000;
```

## Typography

- Display/headings: Hanken Grotesk
- Body/UI: Inter

Do not copy the Figma-generated React/Tailwind output directly. The Drupal implementation uses semantic Twig, reusable Drupal components and maintainable CSS.

## Asset checklist

Export and commit the exact Figma assets into:

```text
web/themes/custom/reg_theme/assets/images/
web/themes/custom/reg_theme/assets/icons/
```

Required assets:

- REG logo
- Hero city image
- Operations/impact background
- Transmission map illustration
- Two news images
- Featured-video thumbnails
- Five partner logos
- Service-card icons
- Navigation/search icons
- Video/play and arrow icons

The Figma MCP download URLs are temporary and must not be committed.
