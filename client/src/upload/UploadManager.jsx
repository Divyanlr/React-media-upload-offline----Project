import React, { useEffect, useRef, useState } from "react";
import axios from "axios";
import { v4 as uuidv4 } from "uuid";

// API base URL
axios.defaults.baseURL = "http://127.0.0.1:8000";

// Upload config
const CHUNK_SIZE = 1 * 1024 * 1024; 
const MAX_CONCURRENCY = 3;
const MAX_RETRIES = 3;

// Delay between chunks so PAUSE/CANCEL can react
const UPLOAD_DELAY_MS = 1900;

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

export default function UploadManager({ files, onComplete }) {
  const [uploads, setUploads] = useState([]);
  const activeCount = useRef(0);
  const cancelTokens = useRef({});
  const processing = useRef(false);

  // Detect new uploads and add them
  useEffect(() => {
    const newList = files.map((f) => ({
      id: uuidv4(),
      file: f.file ?? f,
      name: f.name ?? f.file?.name ?? "unknown",
      size: f.size ?? f.file?.size ?? 0,
      type: f.type ?? f.file?.type ?? "",
      uploadId: uuidv4(),
      progress: 0,
      status: "idle",
      pausedByUser: false, // NEW: mark when user explicitly paused
      chunkIndex: 0,
      totalChunks: Math.max(1, Math.ceil((f.size ?? f.file?.size ?? 0) / CHUNK_SIZE)),
      error: null,
      startedAt: null,
      finishedAt: null,
      running: false,
    }));

    setUploads((prev) => {
      const map = {};
      prev.forEach((p) => (map[`${p.name}-${p.size}`] = p));

      const merged = [...prev];
      newList.forEach((n) => {
        if (!map[`${n.name}-${n.size}`]) merged.push(n);
      });

      return merged;
    });
  }, [files]);

  const uploadsRef = useRef([]);
  useEffect(() => {
    uploadsRef.current = uploads;
    if (uploads.length > 0) startProcessing();
  }, [uploads]);

  function updateUpload(id, patch) {
    setUploads((prev) => prev.map((u) => (u.id === id ? { ...u, ...patch } : u)));
  }

  function startProcessing() {
    if (processing.current) return;
    processing.current = true;
    processQueue().finally(() => {
      processing.current = false;
    });
  }

  async function processQueue() {
    while (true) {
      await sleep(20);

      if (activeCount.current >= MAX_CONCURRENCY) {
        await sleep(50);
        continue;
      }

      // candidate must not be pausedByUser
      const candidate = uploadsRef.current.find(
        (u) =>
          (u.status === "idle" || u.status === "uploading") &&
          !u.running &&
          u.status !== "paused" &&
          u.status !== "finished" &&
          u.status !== "error" &&
          u.status !== "cancelled" &&
          !u.pausedByUser
      );

      if (!candidate) break;

      updateUpload(candidate.id, { running: true, status: "uploading" });
      activeCount.current++;

      uploadFile(candidate)
        .finally(() => {
          activeCount.current = Math.max(0, activeCount.current - 1);
          updateUpload(candidate.id, { running: false });
        });
    }
  }

  async function uploadFile(snapshot) {
    const getLatest = () => uploadsRef.current.find((x) => x.id === snapshot.id);
    let item = getLatest();
    if (!item) return;

    // INITIATE
    try {
      await axios.post("/api/upload/initiate", {
        uploadId: item.uploadId,
        fileName: item.name,
        size: item.size,
        type: item.type,
        totalChunks: item.totalChunks,
      });
    } catch (err) {
      updateUpload(item.id, { status: "error", error: "initiate failed", running: false });
      return;
    }

    // UPLOAD CHUNKS
    for (let i = item.chunkIndex; i < item.totalChunks; i++) {
      let cur = getLatest();
      if (!cur) return;

      // If user paused explicitly, wait here until resume (sticky pause)
      if (cur.pausedByUser) {
        // keep waiting until user resumes (which unsets pausedByUser)
        while (true) {
          await sleep(200);
          const again = getLatest();
          if (!again) return;
          if (again.status === "cancelled") return;
          if (!again.pausedByUser) break;
        }
      }

      // Check paused status (non-user pauses) and cancelled
      if (cur.status === "paused") {
        while (true) {
          await sleep(200);
          const again = getLatest();
          if (!again) return;
          if (again.status === "cancelled") return;
          if (again.status !== "paused") break;
        }
      }
      if (cur.status === "cancelled") return;

      // Ensure real file
      let realFile = cur.file;
      if (!realFile?.slice && realFile?.file?.slice) realFile = realFile.file;
      if (!realFile || typeof realFile.slice !== "function") {
        updateUpload(cur.id, { status: "error", error: "invalid file object", running: false });
        return;
      }

      const start = i * CHUNK_SIZE;
      const end = Math.min(cur.size, start + CHUNK_SIZE);
      const chunk = realFile.slice(start, end);

      let attempts = 0;
      let success = false;
      let lastErr = null;

      while (!success && attempts <= MAX_RETRIES) {
        try {
          const form = new FormData();
          form.append("uploadId", cur.uploadId);
          form.append("index", i);
          form.append("chunk", chunk, cur.name + ".part." + i);

          const source = axios.CancelToken.source();
          cancelTokens.current[`${cur.id}-${i}`] = source;

          const resp = await axios.post("/api/upload/chunk", form, {
            headers: { "Content-Type": "multipart/form-data" },
            timeout: 30000,
            cancelToken: source.token,
          });

          delete cancelTokens.current[`${cur.id}-${i}`];

          if (resp.data?.error) throw new Error(resp.data.error);
          success = true;

          const latest = getLatest();
          const progress = Math.round(((i + 1) / latest.totalChunks) * 100);

          // Preserve explicit user pause: if pausedByUser is true keep status paused
          let newStatus = latest.status;
          if (latest.pausedByUser) {
            newStatus = "paused";
          } else if (!["paused", "cancelled", "error", "finished"].includes(newStatus)) {
            newStatus = "uploading";
          }

          updateUpload(latest.id, {
            chunkIndex: i + 1,
            progress,
            status: newStatus,
          });

          // small delay so UI events can be processed
          if (UPLOAD_DELAY_MS > 0) await sleep(UPLOAD_DELAY_MS);
        } catch (err) {
          attempts++;
          lastErr = err;

          if (axios.isCancel(err)) {
            updateUpload(cur.id, { status: "cancelled", running: false });
            return;
          }

          if (attempts > MAX_RETRIES) {
            updateUpload(cur.id, { status: "error", error: "chunk failed", running: false });
            return;
          }

          await sleep(400 * attempts);
        }
      }
    }

    // FINALIZE
    const final = getLatest();
    if (!final) return;

    updateUpload(final.id, { status: "finalizing" });

    try {
      const resp = await axios.post("/api/upload/finalize", {
        uploadId: final.uploadId,
        fileName: final.name,
      });

      if (resp.data?.error) {
        updateUpload(final.id, { status: "error", error: resp.data.error });
        return;
      }

      updateUpload(final.id, {
        status: "finished",
        progress: 100,
        finishedAt: Date.now(),
        running: false,
      });

      onComplete?.({
        fileName: final.name,
        size: final.size,
        uploadId: final.uploadId,
      });
    } catch (err) {
      updateUpload(final.id, { status: "error", error: "finalize failed" });
    }
  }

  // USER CONTROLS
  function pause(id) {
    // mark as paused by user (sticky) and status paused
    updateUpload(id, { status: "paused", pausedByUser: true });
  }

  function resume(id) {
    // clear user pause and put it back to idle so processQueue can pick it up
    updateUpload(id, { status: "idle", pausedByUser: false, error: null });
    // restart processing loop
    startProcessing();
  }

  function cancel(id) {
    // cancel in-flight chunk requests for this upload
    Object.keys(cancelTokens.current).forEach((key) => {
      if (key.startsWith(id)) {
        try {
          cancelTokens.current[key].cancel("user cancelled");
        } catch { }
      }
    });
    updateUpload(id, { status: "cancelled", pausedByUser: false });
  }

  const overall =
    uploads.length === 0
      ? 0
      : Math.round(uploads.reduce((s, u) => s + (u.progress || 0), 0) / uploads.length);

  return (
    <div className="upload-manager">
      <div className="header">
        <div style={{ width: "100%" }}>
          <div className="overall">Overall {overall}%</div>
        </div>
      </div>

      <div className="items">
        {uploads.map((u) => (
          <div key={u.id} className="upload-item">
            <div className="meta">
              <div>{u.name}</div>
              <div className="small">{u.progress}% • {u.status}</div>
            </div>

            <div className="controls">
              <button
                onClick={() => pause(u.id)}
                disabled={u.status === "paused" || u.status === "finished" || u.status === "cancelled"}
              >
                Pause
              </button>

              <button
                onClick={() => cancel(u.id)}
                // enable Cancel while pausedByUser so user can cancel while paused
                disabled={u.pausedByUser ? false : (u.status === "finished" || u.status === "cancelled")}
              >
                Cancel
              </button>


              <button
                onClick={() => resume(u.id)}
                disabled={!u.pausedByUser}
              >
                Resume
              </button>
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
