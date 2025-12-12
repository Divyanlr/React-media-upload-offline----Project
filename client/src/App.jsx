import React from "react";
import UploadArea from "./upload/UploadArea";

export default function App() {
  return (
    <div className="app-shell">
      <header>
        <h1>Media File Upload System</h1>
        <p className="subtitle">
          Chunked uploads, pause/resume, retry and upload history
        </p>
      </header>

      <main>
        <UploadArea />
      </main>

      <footer>
        <small>Made with care, use it to test chunked uploads</small>
      </footer>
    </div>
  );
}
