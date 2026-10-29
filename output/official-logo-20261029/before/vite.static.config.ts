import fs from "fs"
import path from "path"
import tailwindcss from "@tailwindcss/vite"
import react from "@vitejs/plugin-react"
import { defineConfig } from "vite"

const staticAssets = [
  "icon.png",
  "nuers-favicon.png",
  "favicon.ico",
  "apple-touch-icon.png",
  "app-icon-192.png",
  "app-icon-512.png",
  "site.webmanifest",
  "site.webmanifest.json",
  "nuers_wBG.jpg",
  "NUERSLOGO.png",
  "NUERSLOGOWHITE.png",
  "NUERS copy.png",
  "NUERS.png",
  "NUERS_WHITE.png",
  "robots.txt",
  "vite.svg",
  "auth-assets",
  "dashboard-assets",
  "website-assets",
  "pdf-assets",
  "guides",
]

const liveApiBaseUrl = (process.env.VITE_API_BASE_URL ?? "https://nuers.net/public").replace(/\/$/, "")

function copyStaticAssets() {
  return {
    name: "copy-static-assets",
    closeBundle() {
      const outputDir = path.resolve(__dirname, "dist")

      for (const asset of staticAssets) {
        const source = path.resolve(__dirname, "public", asset)
        const destination = path.resolve(outputDir, asset)

        if (fs.existsSync(source)) {
          fs.mkdirSync(path.dirname(destination), { recursive: true })
          fs.cpSync(source, destination, { recursive: true })
        }
      }

      fs.writeFileSync(path.resolve(outputDir, "_redirects"), "/*    /index.html   200\n")
      fs.writeFileSync(
        path.resolve(outputDir, ".htaccess"),
        `<IfModule mod_rewrite.c>
    <IfModule mod_negotiation.c>
        Options -MultiViews -Indexes
    </IfModule>

    RewriteEngine On

    RewriteCond %{REQUEST_FILENAME} -f [OR]
    RewriteCond %{REQUEST_FILENAME} -d
    RewriteRule ^ - [L]

    RewriteRule ^ index.html [L]
</IfModule>
`,
      )
    },
  }
}

export default defineConfig({
  define: {
    "import.meta.env.VITE_API_BASE_URL": JSON.stringify(liveApiBaseUrl),
  },
  plugins: [react(), tailwindcss(), copyStaticAssets()],
  publicDir: false,
  build: {
    outDir: "dist",
    emptyOutDir: true,
  },
  resolve: {
    alias: {
      "@": path.resolve(__dirname, "./src"),
    },
  },
})
