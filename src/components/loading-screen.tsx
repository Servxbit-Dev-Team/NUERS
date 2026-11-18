type LoadingScreenProps = {
  label?: string;
  variant?: "screen" | "panel";
};

/** Shared startup, route and session loading state; never delays completed work. */
export function LoadingScreen({
  label = "Loading NUERS…",
  variant = "screen",
}: LoadingScreenProps) {
  return (
    <div
      className={`nuers-loading nuers-loading--${variant}`}
      role="status"
      aria-live="polite"
      aria-atomic="true"
    >
      <picture className="nuers-loading__art" aria-hidden="true">
        <source
          media="(prefers-reduced-motion: reduce)"
          srcSet="/app-icon-192.png?v=b50631c79afb"
        />
        <img
          src="/brand/nuers-loading-ring.gif?v=20261029"
          width="168"
          height="168"
          alt=""
          fetchPriority="high"
        />
      </picture>
      <span className="nuers-loading__brand">NUERS</span>
      <p className="nuers-loading__label">{label}</p>
    </div>
  );
}
