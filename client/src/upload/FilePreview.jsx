import React, { useEffect, useState } from "react";

export default function FilePreview({ fileMeta, onRemove }) {
  const [preview, setPreview] = useState(null);

  useEffect(() => {
    // generate thumbnail for images, and simple icon for videos
    if (fileMeta.file.type.startsWith("image/")) {
      const reader = new FileReader();
      reader.onload = (e) => setPreview(e.target.result);
      reader.readAsDataURL(fileMeta.file);
    } else {
      setPreview(null);
    }
  }, [fileMeta]);

  return (
    <div className="file-item">
      <img
        className="thumb"
        src={preview || "/placeholder-video.png"}
        alt="thumb"
      />
      <div className="meta">
        <div>
          <strong>{fileMeta.name}</strong>
        </div>
        <div className="small">
          {Math.round(fileMeta.size / 1024)} KB • {fileMeta.type || "unknown"}
        </div>
      </div>
      <div>
        <button className="btn ghost small" onClick={onRemove}>
          Remove
        </button>
      </div>
    </div>
  );
}
