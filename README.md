# Renocon.ca

Static website for [Renocon](https://renocon.ca), a restaurant and commercial general contractor in Ontario and Alberta.

This is HTML, CSS, and JavaScript only. There is no WordPress, PHP, or database.

## Local preview

```bash
python3 -m http.server 4173
```

Then open `http://localhost:4173`.

## Pages

- `index.html` — home
- `about.html` — approach
- `services.html` — contracting and project management
- `gallery.html` — completed work
- `contact.html` — offices and enquiry form
- `privacy.html` — privacy policy

## Going live

Upload the files in this repository (except `.git`) to the web root of `renocon.ca`, replacing the current WordPress install. After that, the domain should serve these pages with no login and no CMS.

The contact form opens a message to `info@renocon.ca` in the visitor’s email app.
