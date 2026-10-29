// src/components/loading-screen.tsx
import { jsx, jsxs } from "react/jsx-runtime";
function LoadingScreen({ label = "Loading NUERS\u2026", variant = "screen" }) {
  return /* @__PURE__ */ jsxs("div", { className: `nuers-loading nuers-loading--${variant}`, role: "status", "aria-live": "polite", "aria-atomic": "true", children: [
    /* @__PURE__ */ jsxs("picture", { className: "nuers-loading__art", "aria-hidden": "true", children: [
      /* @__PURE__ */ jsx("source", { media: "(prefers-reduced-motion: reduce)", srcSet: "/app-icon-192.png?v=b50631c79afb" }),
      /* @__PURE__ */ jsx("img", { src: "/brand/nuers-loading-ring.gif?v=20261029", width: "168", height: "168", alt: "", fetchPriority: "high" })
    ] }),
    /* @__PURE__ */ jsx("span", { className: "nuers-loading__brand", children: "NUERS" }),
    /* @__PURE__ */ jsx("p", { className: "nuers-loading__label", children: label })
  ] });
}
export {
  LoadingScreen
};
