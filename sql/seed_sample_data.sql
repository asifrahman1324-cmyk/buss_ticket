-- ============================================================
-- Sample seed data for Task 5 (Testing / Demonstration)
-- Run AFTER schema.sql. Adds 5 buses, 5 routes, and 8 schedules
-- spread across today and the next few days so the booking
-- search will return results immediately.
-- ============================================================

-- 5 sample buses
INSERT INTO bus (bus_number, bus_name, bus_type, total_seats, status) VALUES
('DHA-AC-101', 'Green Express',   'AC',     36, 'active'),
('DHA-AC-102', 'Royal Coach',     'AC',     40, 'active'),
('DHA-NA-201', 'City Link',       'Non-AC', 44, 'active'),
('DHA-NA-202', 'Highway King',    'Non-AC', 44, 'active'),
('DHA-AC-103', 'Silver Star',     'AC',     32, 'active');

-- 5 sample routes
INSERT INTO route (origin, destination, distance_km, estimated_duration, base_fare) VALUES
('Dhaka',     'Chittagong', 264.00, '6h 30m', 850.00),
('Dhaka',     'Sylhet',     247.00, '5h 45m', 750.00),
('Dhaka',     'Khulna',     334.00, '7h 00m', 900.00),
('Dhaka',     'Rajshahi',   256.00, '5h 30m', 700.00),
('Chittagong','Cox''s Bazar', 152.00, '3h 30m', 450.00);

-- 8 sample schedules across the buses/routes (dates relative to today)
INSERT INTO schedule (bus_id, route_id, departure_date, departure_time, arrival_time, fare, available_seats) VALUES
(1, 1, CURRENT_DATE,     '08:00', '14:30', 850.00, 36),
(2, 1, CURRENT_DATE,     '22:00', '04:30', 900.00, 40),
(3, 2, CURRENT_DATE,     '09:30', '15:15', 750.00, 44),
(4, 4, CURRENT_DATE + 1, '07:00', '12:30', 700.00, 44),
(1, 3, CURRENT_DATE + 1, '21:00', '04:00', 950.00, 36),
(5, 5, CURRENT_DATE + 1, '10:00', '13:30', 450.00, 32),
(2, 2, CURRENT_DATE + 2, '23:00', '04:45', 800.00, 40),
(3, 1, CURRENT_DATE + 2, '08:30', '15:00', 850.00, 44);