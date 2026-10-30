import { Component, Suspense, type ReactNode } from "react";
import { useLocation } from "react-router-dom";
import { LoadingScreen } from "@/components/loading-screen";

class PageErrorBoundary extends Component<{ children: ReactNode }, { failed: boolean }> {
  state = { failed: false };

  static getDerivedStateFromError() {
    return { failed: true };
  }

  render() {
    if (this.state.failed)
      return (
        <section role="alert" className="ne-session-loading">
          <h2>This page could not load</h2>
          <p>Check your connection, then reload to get the latest version.</p>
          <button type="button" onClick={() => window.location.reload()}>
            Reload page
          </button>
        </section>
      );
    return this.props.children;
  }
}

/** Route code loads on demand; the surrounding navigation remains available. */
export function PageBoundary({ children }: { children: ReactNode }) {
  const location = useLocation();
  return (
    <PageErrorBoundary key={location.pathname}>
      <Suspense fallback={<LoadingScreen label="Loading page…" />}>{children}</Suspense>
    </PageErrorBoundary>
  );
}
