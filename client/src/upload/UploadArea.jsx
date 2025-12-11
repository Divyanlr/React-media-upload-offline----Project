import React, { useState, useRef, useEffect } from "react";
import UploadManager from "./UploadManager";
import FilePreview from "./FilePreview";

/*
  UploadArea: lightweight wrapper
  - history starts empty (no reload from localStorage)
  - dedupe history by uploadId when onComplete is called
*/

export default function UploadArea(){
  const [files, setFiles] = useState([]);
  const [history, setHistory] = useState([]); // start empty to avoid large reloads
  const inputRef = useRef();

  useEffect(()=>{
    // persist only the most recent few entries to avoid huge storage
    localStorage.setItem("uploadHistory", JSON.stringify(history.slice(0,50)));
  },[history]);

  function handleFilesPicked(list){
    const arr = Array.from(list).slice(0,10); // limit 10
    const validated = arr.map(file => ({
      id: `${file.name}-${file.size}-${Date.now()}`,
      file,
      name: file.name,
      size: file.size,
      type: file.type
    }));
    setFiles(prev => [...prev, ...validated]);
  }

  function onRemoveFile(id){ setFiles(prev => prev.filter(f => f.id !== id)); }

  function onComplete(entry){
    setHistory(h => {
      const exists = entry.uploadId ? h.find(item => item.uploadId === entry.uploadId) : h.find(item => item.fileName === entry.fileName && item.size === entry.size);
      if (exists) return h;
      // ensure fileName key exists for display
      return [{ fileName: entry.fileName || entry.fileName, size: entry.size, finishedAt: entry.finishedAt, uploadId: entry.uploadId }, ...h].slice(0,50);
    });
    setFiles([]);
  }

  return (
    <div className="upload-card">
      <div className="row">
        <div className="left">
          <div
            className="dropzone"
            onClick={() => inputRef.current && inputRef.current.click()}
            onDragOver={e => { e.preventDefault(); e.currentTarget.classList.add('dragover'); }}
            onDragLeave={e => { e.currentTarget.classList.remove('dragover'); }}
            onDrop={e => { e.preventDefault(); e.currentTarget.classList.remove('dragover'); handleFilesPicked(e.dataTransfer.files); }}
          >
            <p><strong>Drop images or videos here</strong></p>
            <small className="small">Supports image/* and video/* — select up to 10 files</small>
            <input
              ref={inputRef}
              type="file"
              multiple
              accept="image/*,video/*"
              style={{display:'none'}}
              onChange={e => handleFilesPicked(e.target.files)}
            />
          </div>

          <div className="file-list">
            {files.map(f => <FilePreview key={f.id} fileMeta={f} onRemove={() => onRemoveFile(f.id)} />)}
            {files.length===0 && <p className="small">No files selected yet</p>}
          </div>
        </div>

        <div className="right">
          <UploadManager files={files} onComplete={onComplete} />
          <div className="history">
            <h4>Upload history</h4>
            {history.length===0 && <p className="small">No uploads yet</p>}
            <ul>
              {history.map((h,idx)=>(
                <li key={idx} className="small">
                  {h.fileName || 'unknown'} — {Math.round((h.size||0)/1024)} KB — {h.finishedAt ? new Date(h.finishedAt).toLocaleString() : '-'}
                </li>
              ))}
            </ul>
          </div>
        </div>
      </div>
    </div>
  );
}
