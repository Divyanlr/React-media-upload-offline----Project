import React, { useEffect, useRef, useState } from "react";
import axios from "axios";
import { v4 as uuidv4 } from "uuid";

// Let the browser set multipart boundaries
axios.defaults.baseURL = "http://127.0.0.1:8000";

const CHUNK_SIZE = 1 * 1024 * 1024; // 1MB
const MAX_CONCURRENCY = 3;
const MAX_RETRIES = 3;

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

export default function UploadManager({ files, onComplete }) {
  const [uploads, setUploads] = useState([]);
  const activeCount = useRef(0);
  const cancelTokens = useRef({});

  // Disable snapshot restore for now to avoid loops
  useEffect(() => {}, []);

  // Add new selected files
  useEffect(() => {
    const newList = files.map((f) => ({
      id: uuidv4(),
      file: f.file,
      name: f.name,
      size: f.size,
      type: f.type,
      uploadedBytes: 0,
      progress: 0,
      status: "idle",
      uploadId: uuidv4(),
      _finalized: false,
    }));

    if (newList.length) {
      setUploads((prev) => [...prev, ...newList]);
    }
  }, [files]);

  useEffect(() => {
    processQueue();
  }, [uploads]);

  function updateUpload(id, patch) {
    setUploads((prev) => prev.map((u) => (u.id === id ? { ...u, ...patch } : u)));
  }

  async function processQueue() {
    while (activeCount.current < MAX_CONCURRENCY) {
      const next = uploads.find(
        (u) =>
          (u.status === "idle" || u.status === "uploading") &&
          !u._finalized &&
          u.status !== "finalizing" &&
          u.status !== "done" &&
          u.status !== "cancelled"
      );

      if (!next) break;

      activeCount.current += 1;

      (async () => {
        try {
          await uploadFile(next);
        } finally {
          activeCount.current -= 1;
          processQueue();
        }
      })(next);
    }
  }

  async function uploadFile(item) {
    updateUpload(item.id, { status: "uploading" });

    const totalChunks = Math.ceil(item.size / CHUNK_SIZE);

    // INITIALIZE
    try {
      await axios.post("/api/upload/initiate", {
        uploadId: item.uploadId,
        fileName: item.name,
        size: item.size,
        type: item.type,
        totalChunks,
      });
    } catch (err) {
      updateUpload(item.id, { status: "error", error: "initiate failed" });
      return;
    }

    // SEND CHUNKS
    for (let i = 0; i < totalChunks; i++) {
      const start = i * CHUNK_SIZE;
      const end = Math.min(item.size, start + CHUNK_SIZE);

      const chunk = item.file.slice(start, end);

      let attempts = 0;
      let success = false;

      while (!success && attempts <= MAX_RETRIES) {
        try {
          const form = new FormData();
          form.append("uploadId", item.uploadId);
          form.append("chunkIndex", i);
          form.append("totalChunks", totalChunks);
          form.append("chunk", chunk, `${item.name}.part.${i}`);

          const source = axios.CancelToken.source();
          cancelTokens.current[item.id + "_" + i] = source;

          await axios.post("/api/upload/chunk", form, {
            cancelToken: source.token,
          });

          delete cancelTokens.current[item.id + "_" + i];

          updateUpload(item.id, {
            uploadedBytes: end,
            progress: Math.round((end / item.size) * 100),
          });

          success = true;
        } catch (err) {
          attempts++;
          if (attempts > MAX_RETRIES) {
            updateUpload(item.id, { status: "error", error: "chunk retry failed" });
            return;
          }
          await sleep(400);
        }
      }
    }

    // FINALIZE
    updateUpload(item.id, { status: "finalizing" });

    try {
      const resp = await axios.post("/api/upload/finalize", {
        uploadId: item.uploadId,
        fileName: item.name,
      });

      if (resp.data && resp.data.error) {
        updateUpload(item.id, { status: "error", error: resp.data.error });
        return;
      }

      // SUCCESS
      updateUpload(item.id, {
        status: "done",
        progress: 100,
        finishedAt: Date.now(),
      });

      onComplete &&
        onComplete({
          fileName: item.name,
          size: item.size,
          finishedAt: Date.now(),
          uploadId: item.uploadId,
        });
    } catch (err) {
      const msg =
        err?.response?.data?.error ||
        err?.message ||
        "finalize failed (server error)";
      updateUpload(item.id, { status: "error", error: msg });
    }
  }

  function pause(id) {
    updateUpload(id, { status: "paused" });
  }

  function resume(id) {
    updateUpload(id, { status: "idle" });
    processQueue();
  }

  function cancel(id) {
    Object.keys(cancelTokens.current).forEach((key) => {
      if (key.startsWith(id)) {
        try {
          cancelTokens.current[key].cancel("user cancelled");
        } catch {}
      }
    });

    updateUpload(id, { status: "cancelled" });
  }

  const overall =
    uploads.length === 0
      ? 0
      : Math.round(
          uploads.reduce((a, b) => a + (b.progress || 0), 0) / uploads.length
        );

  return (
    <div>
      <div style={{ display: "flex", justifyContent: "space-between" }}>
        <strong>Upload Manager</strong>
        <small>Overall {overall}%</small>
      </div>

      <div className="progress-wrap">
        <div
          className="progress-bar"
          style={{ width: `${overall}%`, background: "var(--primary)" }}
        />
      </div>

      <button className="btn primary" onClick={() => processQueue()}>
        Start All
      </button>

      <div style={{ marginTop: 10 }}>
        {uploads.map((u) => (
          <div key={u.id} style={{ marginBottom: 10 }}>
            <div
              style={{ display: "flex", justifyContent: "space-between" }}
            >
              <span>{u.name}</span>
              <small>
                {u.progress}% • {u.status}
              </small>
            </div>

            <div className="progress-wrap">
              <div
                className="progress-bar"
                style={{ width: `${u.progress}%` }}
              />
            </div>

            <div className="controls">
              {u.status !== "done" && u.status !== "cancelled" && (
                <>
                  {u.status !== "paused" && (
                    <button className="btn ghost" onClick={() => pause(u.id)}>
                      Pause
                    </button>
                  )}
                  {u.status === "paused" && (
                    <button className="btn ghost" onClick={() => resume(u.id)}>
                      Resume
                    </button>
                  )}
                  <button className="btn ghost" onClick={() => cancel(u.id)}>
                    Cancel
                  </button>
                </>
              )}
            </div>

            {u.error && (
              <div className="small" style={{ color: "var(--danger)" }}>
                {u.error}
              </div>
            )}
          </div>
        ))}
      </div>
    </div>
  );
}
