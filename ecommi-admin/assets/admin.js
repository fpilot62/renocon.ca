document.querySelectorAll(".reveal").forEach((button) => {
  button.addEventListener("click", () => {
    const secret = button.previousElementSibling;
    if (!(secret instanceof HTMLElement)) return;
    const hidden = secret.textContent === "••••••••";
    secret.textContent = hidden ? secret.dataset.secret || "" : "••••••••";
    button.textContent = hidden ? "Hide" : "Show";
  });
});
