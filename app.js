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
        showBookingsPopup: false,
        allBookings: [],
        filteredBookings: [],
        selectedFilterDate: new Date().toISOString().split('T')[0],
        showFloorPlanPopup: false,
        selectedFloorPlan: '',
        
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
        
        selectDate(date) {
            const selectedDate = new Date(this.currentDate.getFullYear(), this.currentDate.getMonth(), date);
            console.log("Datum ausgewählt:", selectedDate);
            if (!this.startDate || (this.startDate && this.endDate)) {
                this.startDate = selectedDate;
                this.endDate = null;
            } else if (selectedDate < this.startDate) {
                this.endDate = this.startDate;
                this.startDate = selectedDate;
            } else {
                this.endDate = selectedDate;
            }
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
        },
        
        nextMonth() {
            this.currentDate = new Date(this.currentDate.getFullYear(), this.currentDate.getMonth() + 1, 1);
            this.updateCurrentMonthYear();
            this.getNoOfDays();
        },
        
        submitBooking() {
            if (!this.startDate || !this.endDate || !this.selectedWorkspace || !this.selectedTimePeriod) {
                alert('Bitte wählen Sie einen Zeitraum, eine Zeitspanne und einen Arbeitsplatz aus.');
                return;
            }

            const formattedStartDate = this.formatDateToUTC(this.startDate);
            const formattedEndDate = this.formatDateToUTC(this.endDate);

            let startTime, endTime;
            if (this.selectedTimePeriod === 'vormittags') {
                startTime = '09:00';
                endTime = '12:00';
            } else if (this.selectedTimePeriod === 'nachmittags') {
                startTime = '13:00';
                endTime = '17:00';
            } else {
                alert('Ungültige Zeitspanne ausgewählt.');
                return;
            }

            alert(`Buchung eingereicht für ${this.selectedWorkspace} von ${formattedStartDate} bis ${formattedEndDate}, Zeitspanne: ${startTime} - ${endTime}`);

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
            const day = String(date.getDate()).padStart(2, '0');
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const year = date.getFullYear();
            return `${day}.${month}.${year}`;
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

        filterBookings() {
            this.filteredBookings = this.allBookings.filter(booking => booking.date === this.selectedFilterDate);
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
