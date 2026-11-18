(() => {
  const output = document.getElementById("retry-countdown");
  const seconds = Number(output?.dataset.seconds);
  if (!output || !Number.isFinite(seconds) || seconds <= 0) return;
  const deadline = Date.now() + seconds * 1000;
  const update = () => {
    const remaining = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
    output.textContent = remaining ? `Try again in ${remaining} second${remaining === 1 ? "" : "s"}` : "You can try the action again";
    if (!remaining) {
      clearInterval(timer);
      output.setAttribute("role", "status");
    }
  };
  const timer = setInterval(update, 1000);
  update();
})();
