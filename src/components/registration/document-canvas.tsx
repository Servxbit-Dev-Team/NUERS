import { useEffect, useRef, useState } from "react";
import {
  ChevronLeft,
  ChevronRight,
  ZoomIn,
  ZoomOut,
  RotateCw,
  Scan,
  LoaderCircle,
  FileWarning,
} from "lucide-react";
import type { PDFDocumentProxy, RenderTask } from "pdfjs-dist";
import workerUrl from "pdfjs-dist/build/pdf.worker.min.mjs?url";
import { apiUrl } from "@/lib/api-url";

type Props = { blob: Blob; url: string; name: string; onReady: () => void };
export function DocumentCanvas({ blob, url, name, onReady }: Props) {
  const stage = useRef<HTMLDivElement>(null),
    canvas = useRef<HTMLCanvasElement>(null);
  const [size, setSize] = useState({ width: 600, height: 500 }),
    [pdf, setPdf] = useState<PDFDocumentProxy | null>(null),
    [natural, setNatural] = useState({ width: 0, height: 0 }),
    [page, setPage] = useState(1),
    [pageInput, setPageInput] = useState("1"),
    [zoom, setZoom] = useState(1),
    [rotation, setRotation] = useState(0),
    [busy, setBusy] = useState(true),
    [error, setError] = useState("");
  const isPdf = blob.type === "application/pdf";
  useEffect(() => {
    if (!stage.current) return;
    const observer = new ResizeObserver(([entry]) =>
      setSize({ width: entry.contentRect.width, height: entry.contentRect.height }),
    );
    observer.observe(stage.current);
    return () => observer.disconnect();
  }, []);
  useEffect(() => {
    let active = true;
    let dispose: (() => void) | undefined;
    setError("");
    setBusy(true);
    setPage(1);
    setZoom(1);
    setRotation(0);
    setPdf(null);
    setNatural({ width: 0, height: 0 });
    if (isPdf) {
      void (async () => {
        try {
          const lib = await import("pdfjs-dist");
          if (!active) return;
          lib.GlobalWorkerOptions.workerSrc = workerUrl;
          const task = lib.getDocument({
            data: new Uint8Array(await blob.arrayBuffer()),
            enableXfa: false,
            useWorkerFetch: false,
            useWasm: false,
            isOffscreenCanvasSupported: false,
            disableFontFace: true,
            useSystemFonts: false,
            standardFontDataUrl: apiUrl("/pdf-assets/standard_fonts/"),
            cMapUrl: apiUrl("/pdf-assets/cmaps/"),
            cMapPacked: true,
          });
          dispose = () => {
            void task.destroy();
          };
          const doc = await task.promise;
          if (active) setPdf(doc);
          else void task.destroy();
        } catch (e) {
          if (active) {
            setError(
              e instanceof Error && e.name === "PasswordException"
                ? "This PDF requires a password. Request an unprotected copy for review."
                : "This PDF could not be rendered. Download the file to inspect it or request a replacement.",
            );
            setBusy(false);
          }
        }
      })();
    } else {
      const image = new Image();
      image.onload = () => {
        if (active) {
          setNatural({ width: image.naturalWidth, height: image.naturalHeight });
          setBusy(false);
          onReady();
        }
      };
      image.onerror = () => {
        if (active) {
          setError("This image could not be displayed. Request a clear replacement.");
          setBusy(false);
        }
      };
      image.src = url;
      dispose = () => {
        image.onload = null;
        image.onerror = null;
        image.src = "";
      };
    }
    return () => {
      active = false;
      dispose?.();
    };
  }, [blob, url, isPdf, onReady]);
  useEffect(() => {
    if (!pdf || !canvas.current) return;
    let active = true;
    let render: RenderTask | undefined;
    setBusy(true);
    setError("");
    void (async () => {
      try {
        const source = await pdf.getPage(page);
        if (!active || !canvas.current) return;
        const base = source.getViewport({ scale: 1, rotation });
        const fit = Math.min(
          Math.max(120, size.width - 48) / base.width,
          Math.max(120, size.height - 48) / base.height,
        );
        const scale = Math.min(fit * zoom, Math.sqrt(12000000 / (base.width * base.height)));
        const viewport = source.getViewport({ scale, rotation }),
          ratio = Math.min(
            window.devicePixelRatio || 1,
            1.5,
            Math.sqrt(16000000 / (viewport.width * viewport.height)),
          );
        const target = canvas.current;
        target.width = Math.max(1, Math.floor(viewport.width * ratio));
        target.height = Math.max(1, Math.floor(viewport.height * ratio));
        target.style.width = viewport.width + "px";
        target.style.height = viewport.height + "px";
        render = source.render({
          canvas: target,
          viewport,
          transform: ratio === 1 ? undefined : [ratio, 0, 0, ratio, 0, 0],
        });
        await render.promise;
        if (active) {
          setBusy(false);
          onReady();
        }
      } catch (e) {
        if (active && !(e instanceof Error && e.name === "RenderingCancelledException")) {
          setBusy(false);
          setError("This page could not be rendered. Download the original file to inspect it.");
        }
      }
    })();
    return () => {
      active = false;
      render?.cancel();
    };
  }, [pdf, page, rotation, zoom, size, onReady]);
  useEffect(() => setPageInput(String(page)), [page]);
  const rotated = rotation % 180 !== 0,
    w = rotated ? natural.height : natural.width,
    h = rotated ? natural.width : natural.height;
  const fit =
    w && h
      ? Math.min(Math.max(120, size.width - 48) / w, Math.max(120, size.height - 48) / h, 1)
      : 1;
  function jump() {
    const next = Number(pageInput);
    if (Number.isInteger(next) && next >= 1 && next <= (pdf?.numPages || 1)) setPage(next);
    else setPageInput(String(page));
  }
  return (
    <div className="dv-renderer">
      <div className="dv-toolbar" aria-label="Document preview controls">
        <div>
          {isPdf ? (
            <>
              <button
                title="Previous PDF page"
                aria-label="Previous PDF page"
                disabled={!pdf || page === 1}
                onClick={() => setPage((p) => p - 1)}
              >
                <ChevronLeft size={17} />
              </button>
              <label>
                Page{" "}
                <input
                  aria-label="PDF page number"
                  inputMode="numeric"
                  value={pageInput}
                  disabled={!pdf}
                  onChange={(e) => setPageInput(e.target.value)}
                  onBlur={jump}
                  onKeyDown={(e) => {
                    if (e.key === "Enter") jump();
                  }}
                />{" "}
                of {pdf?.numPages || "—"}
              </label>
              <button
                title="Next PDF page"
                aria-label="Next PDF page"
                disabled={!pdf || page === pdf.numPages}
                onClick={() => setPage((p) => p + 1)}
              >
                <ChevronRight size={17} />
              </button>
            </>
          ) : (
            <span>Image preview</span>
          )}
        </div>
        <div>
          <button
            aria-label="Zoom out"
            disabled={zoom <= 0.25}
            onClick={() => setZoom((z) => Math.max(0.25, z - 0.25))}
          >
            <ZoomOut size={17} />
          </button>
          <span>{Math.round(zoom * 100)}%</span>
          <button
            aria-label="Zoom in"
            disabled={zoom >= 4}
            onClick={() => setZoom((z) => Math.min(4, z + 0.25))}
          >
            <ZoomIn size={17} />
          </button>
          <button aria-label="Fit document to view" title="Fit to view" onClick={() => setZoom(1)}>
            <Scan size={17} />
          </button>
          <button
            aria-label="Rotate document clockwise"
            title="Rotate clockwise"
            onClick={() => setRotation((r) => (r + 90) % 360)}
          >
            <RotateCw size={17} />
          </button>
        </div>
      </div>
      <div ref={stage} className="dv-stage" aria-busy={busy}>
        {error ? (
          <div className="dv-empty" role="alert">
            <FileWarning size={32} />
            <strong>Preview unavailable</strong>
            <p>{error}</p>
          </div>
        ) : (
          <>
            <div className="dv-page-surface">
              {isPdf ? (
                <canvas ref={canvas} aria-label={`${name} · page ${page}`} />
              ) : (
                natural.width > 0 && (
                  <svg
                    role="img"
                    aria-label={name}
                    width={w * fit * zoom}
                    height={h * fit * zoom}
                    viewBox={`0 0 ${w} ${h}`}
                  >
                    <image
                      href={url}
                      width={natural.width}
                      height={natural.height}
                      transform={`translate(${w / 2} ${h / 2}) rotate(${rotation}) translate(${-natural.width / 2} ${-natural.height / 2})`}
                    />
                  </svg>
                )
              )}
            </div>
            {busy && (
              <div className="dv-loading" role="status">
                <LoaderCircle className="animate-spin" size={25} />
                Rendering document…
              </div>
            )}
          </>
        )}
      </div>
      <div className="dv-preview-caption">
        Original file · visual controls do not change the uploaded document.{" "}
        {error
          ? "Use Needs changes to request a readable replacement."
          : "Use zoom and rotation to inspect the evidence."}
      </div>
    </div>
  );
}
