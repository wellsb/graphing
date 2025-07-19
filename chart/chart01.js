document.addEventListener('DOMContentLoaded', () => {

    // --- CONFIGURATION ---
    const MAX_DATA_POINTS = 60;
    const FETCH_INTERVAL = 5000;
    const SENSOR_URL = 'sensor.php';

    // --- DOM ELEMENTS ---
    const dashboardTitleElement = document.getElementById('dashboardTitle');
    const lastPidElement = document.getElementById('lastPidDisplay');
    const lastUpdateElement = document.getElementById('lastUpdateDisplay');
    const failedLoginsElement = document.getElementById('failedLoginsDisplay');
    const authLogElement = document.getElementById('authLogDisplay');

    // --- CHART CREATION HELPERS ---
    // (createLiveChart, createStackedMemoryChart, createDiskUsageChart functions are unchanged)
    function createLiveChart(canvasId, color, forceIntegerTicks = false, yMax = null) {
        const ctx = document.getElementById(canvasId).getContext('2d');
        const [r, g, b] = color;
        const yAxisConfig = {
            min: 0,
            ticks: forceIntegerTicks ? { stepSize: 1, precision: 0 } : {}
        };
        if (yMax) yAxisConfig.max = yMax;

        return new Chart(ctx, {
            type: 'line',
            data: {
                datasets: [{
                    data: [],
                    backgroundColor: `rgba(${r}, ${g}, ${b}, 0.2)`,
                    borderColor: `rgba(${r}, ${g}, ${b}, 1)`,
                    borderWidth: 2,
                    tension: 0.4,
                    pointRadius: 0,
                    fill: true,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { type: 'time', time: { unit: 'second', displayFormats: { second: 'HH:mm:ss' } } },
                    y: yAxisConfig
                },
                plugins: { legend: { display: false } },
                animation: { duration: 500 }
            }
        });
    }

    function createStackedMemoryChart(canvasId) {
        const ctx = document.getElementById(canvasId).getContext('2d');
        return new Chart(ctx, {
            type: 'line',
            data: {
                labels: [], // Timestamps will go here
                datasets: [
                    {
                        label: 'Used',
                        data: [],
                        backgroundColor: 'rgba(231, 76, 60, 0.5)', // Red
                        borderColor: 'rgba(231, 76, 60, 1)',
                        fill: true,
                        tension: 0.4,
                        pointRadius: 0,
                    },
                    {
                        label: 'Cached',
                        data: [],
                        backgroundColor: 'rgba(52, 152, 219, 0.5)', // Blue
                        borderColor: 'rgba(52, 152, 219, 1)',
                        fill: true,
                        tension: 0.4,
                        pointRadius: 0,
                    },
                    {
                        label: 'Free',
                        data: [],
                        backgroundColor: 'rgba(46, 204, 113, 0.5)', // Green
                        borderColor: 'rgba(46, 204, 113, 1)',
                        fill: true,
                        tension: 0.4,
                        pointRadius: 0,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        type: 'time',
                        time: { unit: 'second', displayFormats: { second: 'HH:mm:ss' } }
                    },
                    y: {
                        min: 0,
                        stacked: true, // This is the key for stacking
                        title: { display: true, text: 'Memory (kB)' }
                    }
                },
                plugins: {
                    legend: { position: 'bottom' }
                },
                animation: { duration: 500 }
            }
        });
    }

    function createDiskUsageChart(canvasId) {
        const ctx = document.getElementById(canvasId).getContext('2d');
        return new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Used Space', 'Free Space'],
                datasets: [{
                    data: [0, 100], // Start with default data
                    backgroundColor: ['rgba(255, 99, 132, 0.7)', 'rgba(75, 192, 192, 0.7)'],
                    borderColor: ['rgba(255, 99, 132, 1)', 'rgba(75, 192, 192, 1)'],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }

    // --- CHART CONFIG & INITIALIZATION ---

    // UPDATED: Added running and total process charts back
    const chartConfigs = {
        cpu: { canvasId: 'cpuChart', valueId: 'cpuValue', dataKey: 'cpuUsage', unit: '%', color: [231, 76, 60], yMax: 100 },
        loadAvg1: { canvasId: 'loadAvg1Chart', valueId: 'loadAvg1Value', dataKey: 'loadAvg1', unit: '', color: [255, 99, 132] },
        loadAvg5: { canvasId: 'loadAvg5Chart', valueId: 'loadAvg5Value', dataKey: 'loadAvg5', unit: '', color: [255, 159, 64] },
        loadAvg15: { canvasId: 'loadAvg15Chart', valueId: 'loadAvg15Value', dataKey: 'loadAvg15', unit: '', color: [255, 205, 86] },
        running: { canvasId: 'runningProcessesChart', valueId: 'runningProcessesValue', dataKey: 'runningProcesses', unit: '', color: [54, 162, 235], integer: true },
        total: { canvasId: 'totalProcessesChart', valueId: 'totalProcessesValue', dataKey: 'totalProcesses', unit: '', color: [153, 102, 255], integer: true }
    };

    // Create the simple line charts
    const lineCharts = {};
    for (const key in chartConfigs) {
        const config = chartConfigs[key];
        lineCharts[key] = createLiveChart(config.canvasId, config.color, config.integer, config.yMax);
    }

    // Create the complex charts
    const memoryChart = createStackedMemoryChart('memoryChart');
    const diskUsageChart = createDiskUsageChart('diskUsageChart');

    // Get handles to the value display elements for complex charts
    const diskUsageValueElement = document.getElementById('diskUsageValue');
    const memoryValueElement = document.getElementById('memoryValue');

    // --- UPDATE LOGIC ---
    // (The updateDashboard function is unchanged and will work with the new chartConfigs)
    function updateDashboard(jsonData) {
        if (!jsonData || !jsonData.hostname) {
            console.error("Received invalid data from sensor:", jsonData);
            return;
        }

        const timestamp = new Date(jsonData.timestamp);

        // Update title and stats bar
        dashboardTitleElement.textContent = `${jsonData.hostname}`;
        lastPidElement.textContent = jsonData.lastPid ?? 'N/A';
        lastUpdateElement.textContent = timestamp.toLocaleTimeString();
        failedLoginsElement.textContent = jsonData.failedLoginsLastHour ?? 'N/A';

        // Update auth log display and auto-scroll
        if (jsonData.authLog && Array.isArray(jsonData.authLog)) {
            authLogElement.textContent = jsonData.authLog.join('\n');
            authLogElement.scrollTop = authLogElement.scrollHeight;
        }

        // Update all simple line charts and their value displays
        for (const key in chartConfigs) {
            const config = chartConfigs[key];
            const value = jsonData[config.dataKey];
            if (value !== null && value !== undefined) {
                const data = lineCharts[key].data.datasets[0].data;
                data.push({ x: timestamp, y: value });
                if (data.length > MAX_DATA_POINTS) data.shift();
                document.getElementById(config.valueId).textContent = `${value}${config.unit}`;
                lineCharts[key].update('quiet');
            }
        }

        // Update disk usage chart
        if (jsonData.diskUsed !== null && jsonData.diskFree !== null) {
            diskUsageChart.data.datasets[0].data = [jsonData.diskUsed, jsonData.diskFree];
            const total = jsonData.diskUsed + jsonData.diskFree;
            const percentUsed = total > 0 ? ((jsonData.diskUsed / total) * 100).toFixed(1) : 0;
            diskUsageValueElement.textContent = `${percentUsed}% Used`;
            diskUsageChart.update('quiet');
        }

        // Update stacked memory chart
        if (jsonData.memTotal !== null) {
            const { memUsed, memCached, memFree, memTotal } = jsonData;

            memoryChart.data.labels.push(timestamp);
            memoryChart.data.datasets[0].data.push(memUsed);
            memoryChart.data.datasets[1].data.push(memCached);
            memoryChart.data.datasets[2].data.push(memFree);

            if (memoryChart.data.labels.length > MAX_DATA_POINTS) {
                memoryChart.data.labels.shift();
                memoryChart.data.datasets.forEach(dataset => dataset.data.shift());
            }

            memoryChart.options.scales.y.max = memTotal;
            const percentUsed = memTotal > 0 ? ((memUsed / memTotal) * 100).toFixed(1) : 0;
            memoryValueElement.textContent = `${percentUsed}% Used`;
            memoryChart.update('quiet');
        }
    }

    // --- DATA FETCHING ---
    async function fetchLatestData() {
        try {
            // Add a cache-busting parameter to the URL
            const response = await fetch(`${SENSOR_URL}?t=${Date.now()}`);
            if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
            const jsonData = await response.json();
            updateDashboard(jsonData);
        } catch (error) {
            console.error("Could not fetch data:", error);
            dashboardTitleElement.textContent = 'Connection Error';
        }
    }

    // --- START THE LIVE UPDATE ---
    fetchLatestData();
    setInterval(fetchLatestData, FETCH_INTERVAL);
});