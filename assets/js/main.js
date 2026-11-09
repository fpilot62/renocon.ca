(() => {
  const nav = document.querySelector("[data-nav]");
  const toggle = document.querySelector("[data-nav-toggle]");
  if (toggle && nav) {
    toggle.addEventListener("click", () => {
      const open = nav.classList.toggle("is-open");
      toggle.setAttribute("aria-expanded", String(open));
    });
  }

  const filters = document.querySelectorAll("[data-filter]");
  const items = document.querySelectorAll("[data-category]");
  filters.forEach((btn) => {
    btn.addEventListener("click", () => {
      filters.forEach((item) => item.classList.remove("is-active"));
      btn.classList.add("is-active");
      const value = btn.dataset.filter;
      items.forEach((card) => {
        card.hidden = value !== "all" && card.dataset.category !== value;
      });
    });
  });

  const lightbox = document.querySelector("[data-lightbox]");
  const lightboxImage = lightbox?.querySelector("img");
  const closeLightbox = () => lightbox?.classList.remove("is-open");
  document.querySelectorAll("[data-lightbox-src]").forEach((item) => {
    item.addEventListener("click", () => {
      if (!lightbox || !lightboxImage) return;
      lightboxImage.src = item.dataset.lightboxSrc;
      lightboxImage.alt = item.querySelector("img")?.alt || item.getAttribute("alt") || "";
      lightbox.classList.add("is-open");
    });
  });
  lightbox?.querySelector("button")?.addEventListener("click", closeLightbox);
  lightbox?.addEventListener("click", (event) => {
    if (event.target === lightbox) closeLightbox();
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") closeLightbox();
  });

  const form = document.querySelector("[data-contact-form]");
  const status = document.querySelector("[data-form-status]");
  form?.addEventListener("submit", (event) => {
    event.preventDefault();
    const data = new FormData(form);
    const name = String(data.get("name") || "").trim();
    const email = String(data.get("email") || "").trim();
    const phone = String(data.get("phone") || "").trim();
    const message = String(data.get("message") || "").trim();
    if (!name || !email || !message) {
      if (status) status.textContent = "Please fill in your name, email, and project details.";
      return;
    }
    const body = [
      `Name: ${name}`,
      `Email: ${email}`,
      `Phone: ${phone}`,
      "",
      message,
    ].join("\n");
    const mailto = `mailto:info@renocon.ca?subject=${encodeURIComponent("Project enquiry from " + name)}&body=${encodeURIComponent(body)}`;
    window.location.href = mailto;
    if (status) status.textContent = "Your email app should open with the message ready to send.";
    form.reset();
  });
})();
