function roomieApp() {
    return {
        currentDate: new Date(),
        startDate: null,
        endDate: null,
        selectedWorkspace: '',
        selectedTimePeriod: '',
        bookingToCancel: '',
        currentMonthYear: '',
        blankDays: [],
        daysInMonth: [],
        bookedDates: [],
        showBookingsPopup: false,
        allBookings: [],
        filteredBookings: [],
        selectedFilterDate: new Date().toISOString().split('T')[0],
        showFloorPlanPopup: false,
        selectedFloorPlan: '',
        roomCapacity: 0,

        init() {
            this.updateCurrentMonthYear();
            this.getNoOfDays();
        },

        updateCurrentMonthYear() {
            const months = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
            this.currentMonthYear = `${months[this.currentDate.getMonth()]} ${this.currentDate.getFullYear()}`;
        },

        isToday(date) {
            const today = new Date();
            return date === today.getDate() &&
                this.currentDate.getMonth() === today.getMonth() &&
                this.currentDate.getFullYear() === today.getFullYear();
        },

        isInSelectedRange(date) {
            if (!this.startDate) return false;
            const currentDate = new Date(this.currentDate.getFullYear(), this.currentDate.getMonth(), date);
            if (!this.endDate) return currentDate.getTime() === this.startDate.getTime();
            return currentDate >= this.startDate && currentDate <= this.endDate;
        },

        isBooked(date) {
            const formattedDate = `${this.currentDate.getFullYear()}-${('0' + (this.currentDate.getMonth() + 1)).slice(-2)}-${('0' + date).slice(-2)}`;
            return this.bookedDates.includes(formattedDate);
        },

        selectDate(date) {
            const selectedDate = new Date(this.currentDate.getFullYear(), this.currentDate.getMonth(), date);
            if (!this.startDate || (this.startDate && this.endDate)) {
                this.startDate = selectedDate;
                this.endDate = null;
            } else if (selectedDate < this.startDate) {
                this.endDate = this.startDate;
                this.startDate = selectedDate;
            } else {
                this.endDate = selectedDate;
            }
            this.fetchBookings();
        },

        getNoOfDays() {
            let daysInMonth = new Date(this.currentDate.getFullYear(), this.currentDate.getMonth() + 1, 0).getDate();

            let firstDayOfMonth = new Date(this.currentDate.getFullYear(), this.currentDate.getMonth(), 1).getDay();
            firstDayOfMonth = firstDayOfMonth === 0 ? 7 : firstDayOfMonth;
            let blankDaysArray = [];
            for (let i = 1; i < firstDayOfMonth; i++) {
                blankDaysArray.push(i);
            }

            let daysArray = [];
            for (let i = 1; i <= daysInMonth; i++) {
                daysArray.push(i);
            }

            this.blankDays = blankDaysArray;
            this.daysInMonth = daysArray;
        },

        previousMonth() {
            this.currentDate = new Date(this.currentDate.getFullYear(), this.currentDate.getMonth() - 1, 1);
            this.updateCurrentMonthYear();
            this.getNoOfDays();
            this.fetchMonthlyBookings();
        },

        nextMonth() {
            this.currentDate = new Date(this.currentDate.getFullYear(), this.currentDate.getMonth() + 1, 1);
            this.updateCurrentMonthYear();
            this.getNoOfDays();
            this.fetchMonthlyBookings();
        },

        fetchMonthlyBookings() {
            if (this.selectedWorkspace) {
                const year = this.currentDate.getFullYear();
                const month = this.currentDate.getMonth() + 1;
                fetch(`get_monthly_bookings.php?room_id=${this.selectedWorkspace}&year=${year}&month=${month}`)
                    .then(response => response.json())
                    .then(data => {                        
                        this.bookedDates = data;
                    })
                    .catch(error => console.error('Error:', error));
            }
        },

        submitBooking() {
            if (!this.startDate || !this.endDate || !this.selectedWorkspace || !this.selectedTimePeriod) {
                let errorMessage = 'Bitte wählen Sie ';
                let missingFields = [];

                if (!this.startDate || !this.endDate) missingFields.push('einen Zeitraum');
                if (!this.selectedWorkspace) missingFields.push('einen Raum');
                if (!this.selectedTimePeriod) missingFields.push('eine Zeitspanne');

                errorMessage += missingFields.join(', ') + ' aus.';
                alert(errorMessage);
                return;
            }

            const formattedStartDate = this.formatDateToUTC(this.startDate);
            const formattedEndDate = this.formatDateToUTC(this.endDate);

            let startTime, endTime;
            if (this.selectedTimePeriod === 'ganzerTag') {
                startTime = '09:00';
                endTime = '17:00';
            } else if (this.selectedTimePeriod === 'vormittags') {
                startTime = '09:00';
                endTime = '12:00';
            } else if (this.selectedTimePeriod === 'nachmittags') {
                startTime = '13:00';
                endTime = '17:00';
            } else {
                alert('Ungültige Zeitspanne ausgewählt.');
                return;
            }

            if (this.filteredBookings.length >= this.roomCapacity) {
                alert(`Fehler: Die Kapazität des Raumes ist im angegebenen Zeitraum bereits erreicht.`);
                return;
            }

            alert(`Buchung eingereicht für Raum ${this.selectedWorkspace} von ${formattedStartDate} bis ${formattedEndDate}, Zeitspanne: ${startTime} - ${endTime}`);

            document.getElementById("bookingForm").submit();
        },

        clearDateSelection() {
            this.startDate = null;
            this.endDate = null;
        },

        cancelBooking() {
            if (!this.bookingToCancel) {
                alert('Bitte wählen Sie eine Buchung zum Stornieren aus.');
                return;
            }

            alert(`Buchung ${this.bookingToCancel} wurde storniert.`);
            this.bookingToCancel = '';
        },

        formatDateToUTC(date) {
            return new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate())).toISOString().split('T')[0];
        },

        formatDateToGerman(date) {
            const options = { day: '2-digit', month: '2-digit', year: 'numeric' };
            return new Date(date).toLocaleDateString('de-DE', options);
        },

        get selectedDateRange() {
            if (!this.startDate) return 'Kein Datum ausgewählt';

            const formattedStartDate = this.formatDateToGerman(this.startDate);
            if (!this.endDate) return formattedStartDate;

            const formattedEndDate = this.formatDateToGerman(this.endDate);
            return `${formattedStartDate} - ${formattedEndDate}`;
        },

        openBookingsPopup() {
            this.fetchAllBookings();
            this.showBookingsPopup = true;
            this.filterBookings();
        },

        fetchAllBookings() {
            fetch('get_bookings.php')
                .then(response => response.json())
                .then(data => {
                    this.allBookings = data;
                    this.filterBookings();
                })
                .catch(error => console.error('Error:', error));
        },

        fetchBookings() {
            if (this.selectedWorkspace && this.startDate && this.endDate) {
                fetch(`get_bookings.php?room_id=${this.selectedWorkspace}&start_date=${this.formatDateToUTC(this.startDate)}&end_date=${this.formatDateToUTC(this.endDate)}`)
                    .then(response => response.json())
                    .then(data => {
                        this.filteredBookings = data.sort((a, b) => new Date(a.date) - new Date(b.date));
                        this.getRoomCapacity();
                    })
                    .catch(error => console.error('Error:', error));
            }
        },

        getRoomCapacity() {
            fetch(`get_room_capacity.php?room_id=${this.selectedWorkspace}`)
                .then(response => response.json())
                .then(data => {
                    this.roomCapacity = data.capacity;
                })
                .catch(error => console.error('Error:', error));
        },

        filterBookings() {
            this.filteredBookings = this.allBookings.filter(booking => {
                const bookingDate = new Date(booking.date);
                return bookingDate >= this.startDate && bookingDate <= this.endDate;
            });
        },

        formatTime(time) {
            return time;
        },

        openFloorPlan(floor) {
            this.selectedFloorPlan = floor;
            this.showFloorPlanPopup = true;
        }
    }
}
