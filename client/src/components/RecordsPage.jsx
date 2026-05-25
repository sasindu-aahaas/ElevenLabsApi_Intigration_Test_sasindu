import { useEffect, useState } from "react";

const API_BASE_URL = import.meta.env.VITE_LARAVEL_API_BASE_URL || "http://localhost:8000/api";

const RECORD_FILTERS = [
  { id: "all", label: "All Records" },
  { id: "service_calls", label: "Service Calls" },
  { id: "trip_plans", label: "Trip Plans" }
];

function formatDate(value) {
  if (!value) {
    return "Not available";
  }

  const date = new Date(value);

  return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
}

function renderJsonValue(value) {
  if (!value) {
    return "No data";
  }

  if (typeof value === "string") {
    return value;
  }

  return JSON.stringify(value, null, 2);
}

export default function RecordsPage() {
  const [filter, setFilter] = useState("all");
  const [search, setSearch] = useState("");
  const [records, setRecords] = useState([]);
  const [selectedRecord, setSelectedRecord] = useState(null);
  const [selectedType, setSelectedType] = useState("");
  const [selectedId, setSelectedId] = useState("");
  const [loading, setLoading] = useState(true);
  const [detailLoading, setDetailLoading] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    let cancelled = false;

    async function loadRecords() {
      try {
        setLoading(true);
        setError("");

        const params = new URLSearchParams();
        params.set("type", filter);

        if (search.trim()) {
          params.set("search", search.trim());
        }

        const response = await fetch(`${API_BASE_URL}/records?${params.toString()}`);
        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
          throw new Error(data.message || "Could not load stored records.");
        }

        if (!cancelled) {
          setRecords(data.records || []);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err.message);
        }
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    }

    loadRecords();

    return () => {
      cancelled = true;
    };
  }, [filter, search]);

  async function openRecord(recordType, publicId) {
    try {
      setDetailLoading(true);
      setError("");
      setSelectedType(recordType);
      setSelectedId(publicId);

      const response = await fetch(`${API_BASE_URL}/records/${recordType}/${publicId}`);
      const data = await response.json().catch(() => ({}));

      if (!response.ok) {
        throw new Error(data.message || "Could not load the selected record.");
      }

      setSelectedRecord(data.record || null);
    } catch (err) {
      setError(err.message);
    } finally {
      setDetailLoading(false);
    }
  }

  function downloadRecord(recordType, publicId) {
    window.open(`${API_BASE_URL}/records/${recordType}/${publicId}/download`, "_blank", "noopener,noreferrer");
  }

  return (
    <section className="records-page">
      <div className="records-hero">
        <div>
          <p className="eyebrow">Stored Data Center</p>
          <h1>View saved calls, plans, reports, and downloads in one place</h1>
          <p className="hero-copy">
            Browse service calls and trip plans, open structured details, inspect
            conversation history, and export individual records as JSON files.
          </p>
        </div>
        <div className="status-card">
          <span>Total loaded</span>
          <strong>{records.length}</strong>
          <span>Current filter</span>
          <strong>{RECORD_FILTERS.find((item) => item.id === filter)?.label || "All Records"}</strong>
        </div>
      </div>

      <div className="records-toolbar">
        <div className="records-filter-group" role="group" aria-label="Record type filters">
          {RECORD_FILTERS.map((item) => (
            <button
              key={item.id}
              type="button"
              className={`backend-button ${filter === item.id ? "backend-button-active" : ""}`}
              onClick={() => setFilter(item.id)}
            >
              {item.label}
            </button>
          ))}
        </div>
        <input
          className="records-search"
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          placeholder="Search by ID, status, or summary..."
        />
      </div>

      {error ? <p className="error-text">{error}</p> : null}

      <div className="records-layout">
        <div className="records-list panel">
          <div className="panel-header workspace-header">
            <div>
              <p className="workspace-kicker">Records list</p>
              <h2>Saved Items</h2>
            </div>
          </div>

          {loading ? (
            <p className="empty-state">Loading saved records...</p>
          ) : records.length === 0 ? (
            <p className="empty-state">No stored records matched your filter.</p>
          ) : (
            <div className="records-stack">
              {records.map((record) => (
                <article key={`${record.record_type}-${record.public_id}`} className="record-card">
                  <div className="record-card-head">
                    <div>
                      <span className="record-chip">{record.record_type === "service_call" ? "Service Call" : "Trip Plan"}</span>
                      <h3>{record.public_id}</h3>
                    </div>
                    <span className="call-badge">{record.status}</span>
                  </div>
                  <p className="record-summary">{record.summary}</p>
                  <p className="record-meta">Created: {formatDate(record.created_at)}</p>
                  {record.categories?.length ? (
                    <p className="record-meta">Categories: {record.categories.join(", ")}</p>
                  ) : null}
                  <div className="records-actions">
                    <button
                      type="button"
                      className="primary-button"
                      onClick={() => openRecord(record.record_type, record.public_id)}
                    >
                      View details
                    </button>
                    <button
                      type="button"
                      className="secondary-button"
                      onClick={() => downloadRecord(record.record_type, record.public_id)}
                    >
                      Download
                    </button>
                  </div>
                </article>
              ))}
            </div>
          )}
        </div>

        <div className="records-detail panel">
          <div className="panel-header workspace-header">
            <div>
              <p className="workspace-kicker">Record inspector</p>
              <h2>Detail View</h2>
            </div>
          </div>

          {detailLoading ? (
            <p className="empty-state">Loading record details...</p>
          ) : !selectedRecord ? (
            <p className="empty-state">
              Choose a saved record from the left to inspect the full report and conversation.
            </p>
          ) : (
            <div className="detail-stack">
              <div className="trip-summary-card">
                <span>Identity</span>
                <p><strong>Type:</strong> {selectedType}</p>
                <p><strong>ID:</strong> {selectedId}</p>
                <p><strong>Status:</strong> {selectedRecord.status || "Unknown"}</p>
                <p><strong>Started:</strong> {formatDate(selectedRecord.started_at)}</p>
                <p><strong>Ended:</strong> {formatDate(selectedRecord.ended_at)}</p>
              </div>

              {"customer_profile" in selectedRecord ? (
                <div className="trip-summary-card">
                  <span>Customer Profile</span>
                  <pre className="records-pre">{renderJsonValue(selectedRecord.customer_profile)}</pre>
                </div>
              ) : null}

              {"service_categories" in selectedRecord ? (
                <div className="trip-summary-card">
                  <span>Service Categories</span>
                  <pre className="records-pre">{renderJsonValue(selectedRecord.service_categories)}</pre>
                </div>
              ) : null}

              {"latest_report" in selectedRecord ? (
                <div className="trip-summary-card">
                  <span>Latest Report</span>
                  <pre className="records-pre">{renderJsonValue(selectedRecord.latest_report)}</pre>
                </div>
              ) : null}

              {"final_report" in selectedRecord ? (
                <div className="trip-summary-card">
                  <span>Final Report</span>
                  <pre className="records-pre">{renderJsonValue(selectedRecord.final_report)}</pre>
                </div>
              ) : null}

              {"latest_summary" in selectedRecord ? (
                <div className="trip-summary-card">
                  <span>Trip Summary</span>
                  <pre className="records-pre">{renderJsonValue(selectedRecord.final_summary || selectedRecord.latest_summary)}</pre>
                </div>
              ) : null}

              <div className="trip-summary-card">
                <span>Conversation History</span>
                <pre className="records-pre">{renderJsonValue(selectedRecord.conversation_history)}</pre>
              </div>

              <div className="records-actions">
                <button
                  type="button"
                  className="secondary-button"
                  onClick={() => downloadRecord(selectedType, selectedId)}
                >
                  Download this record
                </button>
              </div>
            </div>
          )}
        </div>
      </div>
    </section>
  );
}
